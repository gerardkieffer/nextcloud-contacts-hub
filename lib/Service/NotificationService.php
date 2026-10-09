<?php

declare(strict_types=1);

namespace OCA\ContactHub\Service;

use OCA\ContactHub\AppInfo\Application;
use OCA\ContactHub\Sync\SyncJob;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;

/**
 * Emails the job owner when a scheduled run pauses a job for unresolved
 * conflicts.
 *
 * Who gets it is NotificationPreferences' decision: the profile address by
 * default, a custom one, or nobody. Opting out is silent; having opted in
 * with no usable address is logged, because that user expects mail and is
 * not getting it.
 *
 * Delivery is always best-effort: the job pauses regardless of whether the
 * email goes out, because the pause itself -- not the notification -- is
 * what keeps an ambiguous identity from being silently pushed or deleted.
 * Nothing here may throw back into the cron tick; every failure is caught,
 * logged, and recorded via `last_mail_failure_at` so the mail-configuration
 * warning can reflect it.
 */
class NotificationService
{
    public const string CONFIG_LAST_FAILURE = 'last_mail_failure_at';

    public function __construct(
        private readonly IMailer $mailer,
        private readonly IUserManager $userManager,
        private readonly IURLGenerator $urlGenerator,
        private readonly IAppConfig $appConfig,
        private readonly IConfig $config,
        private readonly NotificationPreferences $preferences,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function notifyJobPaused(SyncJob $job, int $conflictCount): void
    {
        $prefs = $this->preferences->get($job->userId);
        $email = $prefs['recipient'];
        if ($email === null) {
            if ($prefs['mode'] !== NotificationPreferences::MODE_OFF) {
                $this->logger->warning(
                    'Contacts Hub: cannot notify {user} that job "{job_name}" paused for conflicts -- no usable email address ({mode} mode).',
                    ['user' => $job->userId, 'job_name' => $job->name, 'mode' => $prefs['mode'], 'app' => 'contacthub'],
                );
            }
            return;
        }

        $link = $this->urlGenerator->linkToRouteAbsolute('contacthub.page.index') . '#conflicts';
        $displayName = $this->userManager->get($job->userId)?->getDisplayName() ?? $job->userId;

        try {
            $template = $this->mailer->createEMailTemplate('contacthub.ConflictPause', [
                'jobName' => $job->name,
                'conflictCount' => $conflictCount,
            ]);
            $template->setSubject("Contacts Hub: sync job \"{$job->name}\" paused, conflicts need your review");
            $template->addHeader();
            $template->addHeading('Sync job paused');
            $template->addBodyText(sprintf(
                'The scheduled sync job "%s" found %s that may already exist on the other side under a '
                    . 'different identity. It has been paused so nothing is pushed or deleted while that is '
                    . 'ambiguous.',
                $job->name,
                $conflictCount === 1 ? '1 contact' : "{$conflictCount} contacts",
            ));
            $template->addBodyText('Once every conflict is resolved, the job resumes on its own schedule.');
            $template->addBodyButton('Review conflicts', $link);
            $template->addBodyText(
                'You receive this because conflict notifications are on in Contacts Hub. '
                    . 'Change the address or switch them off under Personal settings → Contacts Hub: '
                    . $this->urlGenerator->linkToRouteAbsolute('settings.PersonalSettings.index', ['section' => Application::APP_ID]),
            );
            $template->addFooter();

            $message = $this->mailer->createMessage();
            $message->setTo([$email => $displayName]);
            $message->useTemplate($template);
            // Marks it as machine-generated (RFC 3834), so auto-responders
            // do not reply to it.
            $message->setAutoSubmitted('auto-generated');

            $failedRecipients = $this->mailer->send($message);
            if ($failedRecipients !== []) {
                $this->recordFailure();
                $this->logger->warning('Contacts Hub: notification email for job {job} was not delivered to {recipients}.', [
                    'job' => $job->id,
                    'recipients' => $failedRecipients,
                    'app' => 'contacthub',
                ]);
            } else {
                $this->recordSuccess();
            }
        } catch (\Throwable $e) {
            $this->recordFailure();
            $this->logger->error('Contacts Hub: failed to send conflict-pause notification for job {job}.', [
                'job' => $job->id,
                'exception' => $e,
                'app' => 'contacthub',
            ]);
        }
    }

    /**
     * Whether Nextcloud's outgoing mail looks able to deliver, so the UI can
     * warn before a notification silently goes nowhere.
     *
     * `IMailer` has no "is mail configured" query: a transport failure is
     * caught inside core and never thrown. So this reads the same signals
     * Nextcloud's own "Email test" setup check reads, with the same
     * interpretation -- an empty `emailTestSuccessful` only counts as
     * unconfigured when `mail_domain` is empty too, because mail set up
     * through occ or config.php never runs the admin test -- and then keeps
     * it honest with whether a real send from this app has failed since.
     *
     * @return array{delivery_disabled: bool, recent_send_failure: bool, likely_configured: bool}
     */
    public function mailStatus(): array
    {
        $disabled = $this->config->getSystemValueString('mail_smtpmode', 'smtp') === 'null';
        $test = $this->appConfig->getValueString('core', 'emailTestSuccessful', '');
        $tested = !($test === '0' || ($test === '' && $this->config->getSystemValueString('mail_domain', '') === ''));
        $recentFailure = $this->appConfig->getValueString(Application::APP_ID, self::CONFIG_LAST_FAILURE, '') !== '';

        return [
            'delivery_disabled' => $disabled,
            'recent_send_failure' => $recentFailure,
            'likely_configured' => !$disabled && $tested && !$recentFailure,
        ];
    }

    private function recordFailure(): void
    {
        $this->appConfig->setValueString(Application::APP_ID, self::CONFIG_LAST_FAILURE, gmdate('Y-m-d H:i:s'));
    }

    /** Clears a prior recorded failure: mail delivery is working again as of this send. */
    private function recordSuccess(): void
    {
        $this->appConfig->deleteKey(Application::APP_ID, self::CONFIG_LAST_FAILURE);
    }
}
