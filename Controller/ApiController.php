<?php
/**
 * Orange Management
 *
 * PHP Version 8.0
 *
 * @package   Modules\HumanResourceTimeRecording
 * @copyright Dennis Eichhorn
 * @license   OMS License 1.0
 * @version   1.0.0
 * @link      https://orange-management.org
 */
declare(strict_types=1);

namespace Modules\HumanResourceTimeRecording\Controller;

use DateTime;
use Modules\HumanResourceManagement\Models\EmployeeMapper;
use Modules\HumanResourceManagement\Models\NullEmployee;
use Modules\HumanResourceTimeRecording\Models\ClockingStatus;
use Modules\HumanResourceTimeRecording\Models\ClockingType;
use Modules\HumanResourceTimeRecording\Models\NullSession;
use Modules\HumanResourceTimeRecording\Models\PermissionState;
use Modules\HumanResourceTimeRecording\Models\Session;
use Modules\HumanResourceTimeRecording\Models\SessionElement;
use Modules\HumanResourceTimeRecording\Models\SessionElementMapper;
use Modules\HumanResourceTimeRecording\Models\SessionMapper;
use phpOMS\Account\PermissionType;
use phpOMS\DataStorage\Database\RelationType;
use phpOMS\Message\Http\RequestStatusCode;
use phpOMS\Message\NotificationLevel;
use phpOMS\Message\RequestAbstract;
use phpOMS\Message\ResponseAbstract;
use phpOMS\Model\Message\FormValidation;

/**
 * HumanResourceTimeRecording controller class.
 *
 * @package Modules\HumanResourceTimeRecording
 * @license OMS License 1.0
 * @link    https://orange-management.org
 * @since   1.0.0
 */
final class ApiController extends Controller
{
    /**
     * Api method to get multiple sessions for an employee
     *
     * @param RequestAbstract  $request  Request
     * @param ResponseAbstract $response Response
     * @param mixed            $data     Generic data
     *
     * @return void
     *
     * @api
     *
     * @since 1.0.0
     */
    public function apiSessionsListForEmployeeGet(RequestAbstract $request, ResponseAbstract $response, $data = null) : void
    {
        $account = (int) ($request->getData('account') ?? $request->header->account);

        if ($request->getData('account') !== null) {
            if (!$this->app->accountManager->get($request->header->account)->hasPermission(
                PermissionType::READ, $this->app->orgId, $this->app->appName, self::MODULE_NAME, PermissionState::SESSION_FOREIGN
            )) {
                $this->fillJsonResponse($request, $response, NotificationLevel::HIDDEN, '', '', []);
            }
        }

        $employee = EmployeeMapper::getFromAccount($account);
        $sessions = SessionMapper::getSessionListForEmployee($employee->getId(), new DateTime($request->getData('start') ?? 'now'), (int) ($request->getData('session') ?? 0), 50);
        $this->fillJsonResponse($request, $response, NotificationLevel::HIDDEN, '', '', $sessions);
    }

    /**
     * Api method to create a session
     *
     * @param RequestAbstract  $request  Request
     * @param ResponseAbstract $response Response
     * @param mixed            $data     Generic data
     *
     * @return void
     *
     * @api
     *
     * @since 1.0.0
     */
    public function apiSessionCreate(RequestAbstract $request, ResponseAbstract $response, $data = null) : void
    {
        $session = $this->createSessionFromRequest($request);

        if ($session === null) {
            $this->fillJsonResponse($request, $response, NotificationLevel::ERROR, 'Session', 'Session couldn\'t be created.', $session);

            return;
        }

        $this->createModel($request->header->account, $session, SessionMapper::class, 'session', $request->getOrigin());
        $this->fillJsonResponse($request, $response, NotificationLevel::OK, 'Session', 'Session successfully created', $session);
    }

    /**
     * Method to create a session from request.
     *
     * @param RequestAbstract $request Request
     *
     * @return null|Session
     *
     * @since 1.0.0
     */
    private function createSessionFromRequest(RequestAbstract $request) : ?Session
    {
        $account = (int) ($request->getData('account') ?? $request->header->account);

        if ($request->getData('account') !== null) {
            if (!$this->app->accountManager->get($request->header->account)->hasPermission(
                PermissionType::CREATE, $this->app->orgId, $this->app->appName, self::MODULE_NAME, PermissionState::SESSION_FOREIGN
            )) {
                return null;
            }
        }

        $employee = EmployeeMapper::getFromAccount($account);
        $type     = (int) $request->getData('type');
        $status   = (int) $request->getData('status');

        if ($employee instanceof NullEmployee) {
            return null;
        }

        $session = new Session($employee);
        $session->setType(ClockingType::isValidValue($type) ? $type : ClockingType::OFFICE);

        if (ClockingStatus::isValidValue($status)) {
            // a custom datetime can only be set if the user is allowed to create a session for a foreign account or if the session is a vacation
            $dt = $request->getData('datetime') !== null
                && ($request->getData('account') !== null
                    || $type === ClockingType::VACATION
                ) ? new \DateTime($request->getData('datetime')) : new \DateTime('now');

            $element = new SessionElement($session, $dt);
            $element->setStatus($status);

            $session->addSessionElement($element);
        }

        return $session;
    }

    /**
     * Api method to create a session element
     *
     * @param RequestAbstract  $request  Request
     * @param ResponseAbstract $response Response
     * @param mixed            $data     Generic data
     *
     * @return void
     *
     * @api
     *
     * @since 1.0.0
     */
    public function apiSessionElementCreate(RequestAbstract $request, ResponseAbstract $response, $data = null) : void
    {
        if ((int) $request->getData('status') === ClockingStatus::START) {
            $this->apiSessionCreate($request, $response);

            return;
        }

        if (!empty($val = $this->validateSessionElementCreate($request))) {
            $response->set($request->uri->__toString(), new FormValidation($val));
            $response->header->status = RequestStatusCode::R_400;

            return;
        }

        $element = $this->createSessionElementFromRequest($request);

        if ($element === null) {
            $this->fillJsonResponse($request, $response, NotificationLevel::ERROR, 'Session Element', 'You cannot create a session element for another person!', $element);

            return;
        }

        if ($element->getStatus() === ClockingStatus::END) {
            $session = SessionMapper::get($element->session->getId());
            $session->addSessionElement($element);
            SessionMapper::update($session);
        }

        $this->createModel($request->header->account, $element, SessionElementMapper::class, 'element', $request->getOrigin());

        $this->fillJsonResponse($request, $response, NotificationLevel::OK, 'Session Element', 'Session Element successfully created', $element);
    }

    /**
     * Validate session element create request
     *
     * @param RequestAbstract $request Request
     *
     * @return array<string, bool> Returns the validation array of the request
     *
     * @since 1.0.0
     */
    private function validateSessionElementCreate(RequestAbstract $request) : array
    {
        $val = [];
        if (($val['session'] = empty($request->getData('session')) || !\is_numeric($request->getData('session')))) {
            return $val;
        }

        return [];
    }

    /**
     * Method to create session element from request.
     *
     * @param RequestAbstract $request Request
     *
     * @return SessionElement Returns the created session element from the request
     *
     * @since 1.0.0
     */
    private function createSessionElementFromRequest(RequestAbstract $request) : ?SessionElement
    {
        $account = (int) ($request->getData('account') ?? $request->header->account);

        /** @var Session $session */
        $session = SessionMapper::get((int) $request->getData('session'), RelationType::ALL, 6);

        // cannot create session element for none existing session
        if ($session === null || $session instanceof NullSession) {
            return null;
        }

        // account and owner of the session don't match = exception!
        if ($session->getEmployee()->profile->account->getId() !== $account) {
            return null;
        }

        // check permissions to edit session and create session element of a foreign account
        if ($request->getData('account') !== null
            || $session->getEmployee()->profile->account->getId() !== $request->header->account
        ) {
            if (!$this->app->accountManager->get($request->header->account)->hasPermission(
                PermissionType::CREATE, $this->app->orgId, $this->app->appName, self::MODULE_NAME, PermissionState::SESSION_ELEMENT_FOREIGN
            )) {
                return null;
            }
        }

        $status = (int) $request->getData('status');

        // a custom datetime can only be set if the user is allowed to create a session for a foreign account or if the session is a vacation
        $dt = $request->getData('datetime') !== null
                && ($request->getData('account') !== null
                    || $session->getType() === ClockingType::VACATION
                ) ? new \DateTime($request->getData('datetime')) : new \DateTime('now');

        $element = new SessionElement($session, $dt);
        $element->setStatus(ClockingStatus::isValidValue($status) ? $status : ClockingStatus::END);

        return $element;
    }

    /**
     * Api method to update a session
     *
     * @param RequestAbstract  $request  Request
     * @param ResponseAbstract $response Response
     * @param mixed            $data     Generic data
     *
     * @return void
     *
     * @api
     *
     * @since 1.0.0
     */
    public function apiSessionUpdate(RequestAbstract $request, ResponseAbstract $response, $data = null) : void
    {
    }

    /**
     * Api method to update a session element
     *
     * @param RequestAbstract  $request  Request
     * @param ResponseAbstract $response Response
     * @param mixed            $data     Generic data
     *
     * @return void
     *
     * @api
     *
     * @since 1.0.0
     */
    public function apiSessionElementUpdate(RequestAbstract $request, ResponseAbstract $response, $data = null) : void
    {
    }

    /**
     * Api method to update a session
     *
     * @param RequestAbstract  $request  Request
     * @param ResponseAbstract $response Response
     * @param mixed            $data     Generic data
     *
     * @return void
     *
     * @api
     *
     * @since 1.0.0
     */
    public function apiSessionDelete(RequestAbstract $request, ResponseAbstract $response, $data = null) : void
    {
    }

    /**
     * Api method to update a session element
     *
     * @param RequestAbstract  $request  Request
     * @param ResponseAbstract $response Response
     * @param mixed            $data     Generic data
     *
     * @return void
     *
     * @api
     *
     * @since 1.0.0
     */
    public function apiSessionElementDelete(RequestAbstract $request, ResponseAbstract $response, $data = null) : void
    {
    }
}
