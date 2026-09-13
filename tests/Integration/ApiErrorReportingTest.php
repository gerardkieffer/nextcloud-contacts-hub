<?php

declare(strict_types=1);

namespace OCA\ContactHub\Tests\Integration;

use OCA\ContactHub\Controller\ApiController;
use OCA\ContactHub\Service\NotFoundException;
use OCA\ContactHub\Service\ValidationException;
use OCA\ContactHub\Sync\RunAlreadyActive;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * What the SPA is handed when an action fails.
 *
 * The bug this exists for: a sync job whose stored endpoint password had gone
 * stale did nothing at all on click. No toast, no note, no change on the
 * page -- while nextcloud.log held a completely clear 401 "Username or
 * password was incorrect". respond() mapped only the two exceptions the
 * service layer raises deliberately, so a DavException escaped it, Nextcloud
 * produced an OCS envelope with no message in it, and the browser had
 * nothing to render.
 *
 * A sync fails for reasons that live on the far side of a network, so
 * "unexpected" is the ordinary case here rather than the exceptional one,
 * and the response has to carry something a human can read.
 */
final class ApiErrorReportingTest extends TestCase
{
    private function controller(LoggerInterface $logger = new NullLogger()): ApiController
    {
        return new class (
            Server::get(IRequest::class),
            Server::get(IUserSession::class),
            $logger,
        ) extends ApiController {
            public function call(callable $action): DataResponse
            {
                return $this->respond($action);
            }
        };
    }

    public function testAnUnexpectedFailureComesBackWithItsMessage(): void
    {
        $response = $this->controller()->call(static function (): never {
            throw new \RuntimeException('Username or password was incorrect');
        });

        self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
        self::assertSame(
            'Username or password was incorrect',
            $response->getData()['message'] ?? null,
            'the SPA has nothing else to show the user',
        );
    }

    public function testAnUnexpectedFailureIsStillLoggedWithItsTrace(): void
    {
        // Catching it here is exactly what stops Nextcloud logging it for us,
        // and the trace is worth more than the message.
        $logger = new class extends NullLogger {
            /** @var list<array<string, mixed>> */
            public array $errors = [];

            public function error($message, array $context = []): void
            {
                $this->errors[] = $context;
            }
        };

        $this->controller($logger)->call(static function (): never {
            throw new \RuntimeException('boom');
        });

        self::assertCount(1, $logger->errors);
        self::assertInstanceOf(\Throwable::class, $logger->errors[0]['exception'] ?? null);
    }

    public function testTheDeliberateExceptionsKeepTheirOwnStatuses(): void
    {
        $validation = $this->controller()->call(static function (): never {
            throw new ValidationException(['base_url' => 'Required'], 'Invalid');
        });
        self::assertSame(Http::STATUS_BAD_REQUEST, $validation->getStatus());
        self::assertSame(['base_url' => 'Required'], $validation->getData()['errors']);

        $missing = $this->controller()->call(static function (): never {
            throw new NotFoundException('No such job');
        });
        self::assertSame(Http::STATUS_NOT_FOUND, $missing->getStatus());

        // 409 moved here from JobController::run when that action started
        // going through respond() like every other one. The SPA tells these
        // apart by status, so a regression here is a silent one.
        $busy = $this->controller()->call(static function (): never {
            throw new RunAlreadyActive('Already running');
        });
        self::assertSame(Http::STATUS_CONFLICT, $busy->getStatus());
    }

    public function testASuccessfulActionIsUntouched(): void
    {
        $response = $this->controller()->call(static fn(): array => ['ok' => true]);

        self::assertSame(Http::STATUS_OK, $response->getStatus());
        self::assertSame(['ok' => true], $response->getData());
    }
}
