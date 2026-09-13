<?php

declare(strict_types=1);

namespace OCA\ContactHub\Controller;

use OCA\ContactHub\AppInfo\Application;
use OCA\ContactHub\Service\NotFoundException;
use OCA\ContactHub\Service\ValidationException;
use OCA\ContactHub\Sync\RunAlreadyActive;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Shared plumbing for this app's OCS controllers.
 *
 * Two jobs: hand every action the current user's id, and turn the service
 * layer's exceptions into responses the SPA can render.
 *
 * userId() is not a convenience. It is the access-control boundary -- every
 * mapper query filters on it -- so actions take it from the session here
 * rather than accepting it as a parameter, which would let a request name
 * someone else.
 */
abstract class ApiController extends OCSController
{
    public function __construct(
        IRequest $request,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    protected function userId(): string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            // Route attributes already require a login; reaching here means
            // a misconfiguration, and guessing an identity would be worse.
            throw new \RuntimeException('No user in session.');
        }

        return $user->getUID();
    }

    /**
     * Run $action, mapping service exceptions onto HTTP.
     *
     * ValidationException carries per-field messages so the SPA can put each
     * one next to the input that caused it -- the old app could only
     * re-render the whole form with a single line at the top.
     *
     * @param callable(): mixed $action
     */
    protected function respond(callable $action): DataResponse
    {
        try {
            return new DataResponse($action());
        } catch (ValidationException $e) {
            return new DataResponse(
                ['message' => $e->getMessage(), 'errors' => $e->errors],
                Http::STATUS_BAD_REQUEST,
            );
        } catch (NotFoundException $e) {
            return new DataResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (RunAlreadyActive $e) {
            // Someone else is mid-run on this job. The browser's auto-resume
            // racing a cron tick makes this ordinary rather than exceptional,
            // and the SPA says so in those words rather than as a failure.
            return new DataResponse(['message' => $e->getMessage()], Http::STATUS_CONFLICT);
        } catch (\Throwable $e) {
            // Anything else: a dead endpoint, wrong credentials, a server
            // answering nonsense. Letting it escape hands the SPA an OCS
            // envelope with no message in it, and the page then has nothing
            // to show -- which is how a job with a stale password came to
            // fail completely silently in the browser while the log held a
            // perfectly clear 401 "Username or password was incorrect".
            //
            // The raw message goes to the user deliberately. A sync talks to
            // a server this app knows nothing about, so a curated message
            // would have to be a guess, and "something went wrong" is exactly
            // what sent someone to the server log in the first place. The
            // reader is the same person who configured the endpoint.
            //
            // Still logged with the exception, because catching it here is
            // what stops Nextcloud from doing that for us, and the trace is
            // worth more than the message.
            $this->logger->error('Contacts Hub: request failed.', ['exception' => $e]);

            return new DataResponse(['message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }
}
