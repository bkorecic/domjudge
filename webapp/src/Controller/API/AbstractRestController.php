<?php declare(strict_types=1);

namespace App\Controller\API;

use App\Entity\Contest;
use App\Service\ConfigurationService;
use App\Service\DOMJudgeService;
use App\Service\EventLogService;
use App\Utils\Utils;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\QueryBuilder;
use Exception;
use FOS\RestBundle\Controller\AbstractFOSRestController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Class AbstractRestController
 * @package App\Controller\API
 */
abstract class AbstractRestController extends AbstractFOSRestController
{
    /**
     * @var EntityManagerInterface
     */
    protected $em;

    /**
     * @var DOMJudgeService
     */
    protected $dj;

    /**
     * @var ConfigurationService
     */
    protected $config;

    /**
     * @var EventLogService
     */
    protected $eventLogService;

    /**
     * AbstractRestController constructor.
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        DOMJudgeService $dj,
        ConfigurationService $config,
        EventLogService $eventLogService
    ) {
        $this->em              = $entityManager;
        $this->dj              = $dj;
        $this->eventLogService = $eventLogService;
        $this->config          = $config;
    }

    /**
     * Get all objects for this endpoint
     * @throws NonUniqueResultException
     */
    protected function performListAction(Request $request): Response
    {
        return $this->renderData($request, $this->listActionHelper($request));
    }

    /**
     * Get a single object for this endpoint
     * @throws NonUniqueResultException
     */
    protected function performSingleAction(Request $request, string $id): Response
    {
        // Make sure we clear the entity manager class, for when this method is called multiple times by internal requests
        $this->em->clear();

        // Special case for submissions: they can have an external ID even if when running in
        // full local mode, because one can use the API to upload a submission with an external ID
        $idField = $this->getIdField();
        if ($idField === 's.submitid') {
            $queryBuilder = $this->getQueryBuilder($request)
                ->andWhere('(s.externalid IS NULL AND s.submitid = :id) OR s.externalid = :id')
                ->setParameter(':id', $id);
        } else {
            $queryBuilder = $this->getQueryBuilder($request)
                ->andWhere(sprintf('%s = :id', $idField))
                ->setParameter(':id', $id);
        }

        $object = $queryBuilder
            ->getQuery()
            ->getOneOrNullResult();

        if ($object === null) {
            throw new NotFoundHttpException(sprintf('Object with ID \'%s\' not found', $id));
        }

        if ($this instanceof QueryObjectTransformer) {
            $object = $this->transformObject($object);
        }

        return $this->renderData($request, $object);
    }

    /**
     * Render the given data using the correct groups
     *
     * @param mixed    $data
     * @param string[] $extraheaders
     */
    protected function renderData(
        Request $request,
        $data,
        int $statusCode = Response::HTTP_OK,
        array $extraheaders = []
    ): Response {
        $view = $this->view($data);

        // Set the DOMjudge service on the context, so we can use it for permissions
        $view->getContext()->setAttribute('domjudge_service', $this->dj);
        $view->getContext()->setAttribute('config_service', $this->config);

        $groups = ['Default'];
        if (!$request->query->has('strict') || !$request->query->getBoolean('strict')) {
            $groups[] = 'Nonstrict';
        }
        $view->getContext()->setGroups($groups);

        $response = $this->handleView($view);
        $response->setStatusCode($statusCode);
        $response->headers->add($extraheaders);
        return $response;
    }

    /**
     * Render the given create data using the correct groups
     *
     * @param mixed      $data
     * @param string|int $id
     */
    protected function renderCreateData(
        Request $request,
        $data,
        string $routeType,
        $id
    ): Response {
        $params = [
            'id' => $id,
        ];
        if ($routeType !== 'user') {
            $params['cid'] = $request->attributes->get('cid');
        }
        $headers = [
            'Location' => $this->generateUrl("v4_app_api_${routeType}_single", $params, UrlGeneratorInterface::ABSOLUTE_URL),
        ];
        return $this->renderData($request, $data, Response::HTTP_CREATED,
            $headers);
    }

    /**
     * Get the query builder used for getting contests
     * @param bool $onlyActive return only contests that are active
     */
    protected function getContestQueryBuilder(bool $onlyActive = false): QueryBuilder
    {
        $now = Utils::now();
        $qb  = $this->em->createQueryBuilder();
        $qb
            ->from(Contest::class, 'c')
            ->select('c')
            ->andWhere('c.enabled = 1')
            ->orderBy('c.activatetime');

        if ($onlyActive || !$this->dj->checkrole('api_reader')) {
            $qb
                ->andWhere('c.activatetime <= :now')
                ->andWhere('c.deactivatetime IS NULL OR c.deactivatetime > :now')
                ->setParameter(':now', $now);
        }

        // Filter on contests this user has access to
        if (!$this->dj->checkrole('api_reader') && !$this->dj->checkrole('judgehost')) {
            if ($this->dj->checkrole('team') && $this->dj->getUser()->getTeam()) {
                $qb->leftJoin('c.teams', 'ct')
                    ->leftJoin('c.team_categories', 'tc')
                    ->leftJoin('tc.teams', 'tct')
                    ->andWhere('ct.teamid = :teamid OR tct.teamid = :teamid OR c.openToAllTeams = 1')
                    ->setParameter(':teamid', $this->dj->getUser()->getTeam());
            } else {
                $qb->andWhere('c.public = 1');
            }
        }

        return $qb;
    }

    /**
     * @throws NonUniqueResultException
     */
    protected function getContestId(Request $request): int
    {
        if (!$request->attributes->has('cid')) {
            throw new BadRequestHttpException('cid parameter missing');
        }

        $qb = $this->getContestQueryBuilder($request->query->getBoolean('onlyActive', false));
        $qb
            ->andWhere(sprintf('c.%s = :cid', $this->getContestIdField()))
            ->setParameter(':cid', $request->attributes->get('cid'));

        /** @var Contest $contest */
        $contest = $qb->getQuery()->getOneOrNullResult();

        if ($contest === null) {
            throw new NotFoundHttpException(sprintf('Contest with ID \'%s\' not found', $request->attributes->get('cid')));
        }

        return $contest->getCid();
    }

    protected function getContestIdField(): string
    {
        try {
            return $this->eventLogService->externalIdFieldForEntity(Contest::class) ?? 'cid';
        } catch (Exception $e) {
            return 'cid';
        }
    }

    /**
     * Get the query builder to use for request for this REST endpoint
     * @throws NonUniqueResultException
     */
    abstract protected function getQueryBuilder(Request $request): QueryBuilder;

    /**
     * Return the field used as ID in requests
     */
    abstract protected function getIdField(): string;

    /**
     * @return array|int|mixed|string
     * @throws NonUniqueResultException
     */
    protected function listActionHelper(Request $request)
    {
        // Make sure we clear the entity manager class, for when this method is called multiple times by internal requests.
        $this->em->clear();
        $queryBuilder = $this->getQueryBuilder($request);

        if ($request->query->has('ids')) {
            $ids = $request->query->get('ids', []);
            if (!is_array($ids)) {
                throw new BadRequestHttpException('\'ids\' should be an array of ID\'s to fetch');
            }

            $ids = array_unique($ids);

            // Special case for submissions: they can have an external ID even if when running in
            // full local mode, because one can use the API to upload a submission with an external ID
            $idField = $this->getIdField();
            if ($idField === 's.submitid') {
                $or = $queryBuilder->expr()->orX();
                foreach ($ids as $index => $id) {
                    $or->add(sprintf('(s.externalid IS NULL AND s.submitid = :id%s) OR s.externalid = :id%s', $index, $index));
                    $queryBuilder->setParameter(sprintf(':id%s', $index), $id);
                }
                $queryBuilder->andWhere($or);
            } else {
                $queryBuilder
                    ->andWhere(sprintf('%s IN (:ids)', $this->getIdField()))
                    ->setParameter(':ids', $ids);
            }
        }

        $objects = $queryBuilder
            ->getQuery()
            ->getResult();

        if (isset($ids) && count($objects) !== count($ids)) {
            throw new NotFoundHttpException('One or more objects not found');
        }

        if ($this instanceof QueryObjectTransformer) {
            $objects = array_map([$this, 'transformObject'], $objects);
        }
        return $objects;
    }
}
