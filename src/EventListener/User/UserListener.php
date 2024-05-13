<?php

namespace App\EventListener\User;

use ApiPlatform\Api\UrlGeneratorInterface;
use App\Entity\User\User;
use App\Entity\User\UserRegisterConfirmation;
use App\Service\Email\EmailService;
use App\Service\Matomo\MatomoService;
use App\Service\Notification\NotificationService;
use App\Service\Various\ParamService;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class UserListener
{
    public function __construct(
        protected ManagerRegistry $managerRegistry,
        protected UrlGeneratorInterface $urlGeneratorInterface,
        protected ParamService $paramService,
        protected HttpClientInterface $httpClientInterface,
        protected EmailService $emailService,
        protected MatomoService $matomoService,
        protected NotificationService $notificationService
    ) {
        
    }

    public function onPostPersist(PostPersistEventArgs $args) : void {
        try {
            /** @var User $entity */
            $entity = $args->getObject();

            // Ajoute notification bienvenue
            $message = '
            <p>
            Vous venez de créer votre compte et devriez avoir reçu un courrier
            électronique présentant notre site.
            </p>
            <p>
                Si ce n’est pas le cas, n’hésitez pas à
                <a href="'.$this->urlGeneratorInterface->generate('app_contact_contact', [], UrlGeneratorInterface::ABS_URL).'">nous contacter</a>.
            </p>
            <p>
                Vous pouvez paramétrer les notifications que vous souhaitez recevoir
                via <a href="'.$this->urlGeneratorInterface->generate('app_user_user_notification_settings', [], UrlGeneratorInterface::ABS_URL).'">
                vos préférences
                </a>.
            </p>
            ';
            $this->notificationService->addNotification(
                $entity,
                'Bienvenue sur Aides-territoires !',
                $message
            );
            

            // si optin envoi à sendinblue
            if ($entity->isMlConsent()) {
                $sibApiKey = $this->paramService->get('sib_api_key');
                $sibNewsletterId = $this->paramService->get('sib_newsletter_id');
                $sibNewsletterConfirmTemplateId = $this->paramService->get('sib_newsletter_confirm_template_id');
                $url = $this->paramService->get('sib_endpoint').'doubleOptinConfirmation';
                $redirectionUrl = $this->urlGeneratorInterface->generate('app_newsletter_register_success', [], UrlGeneratorInterface::ABS_URL);

                if (trim($sibApiKey) !== '') {
                    $this->httpClientInterface->request(
                        'POST',
                        $url,
                        [
                            'headers' => [
                                'Content-Type' => 'application/json',
                                'Accept' => 'application/json',
                                'api-key' => $sibApiKey
                            ],
                            'json' => [
                                'attributes' => [
                                    'DOUBLE_OPT_IN' => 1,
                                    'includeListIds' => $sibNewsletterId,
                                    'email' => $entity->getEmail(),
                                    'templateId' => $sibNewsletterConfirmTemplateId,
                                    'redirectionUrl' => $redirectionUrl
                                ]
                            ]
                        ]
                    );
                }
            }

            // Mail confirmation inscription
            $userRegisterConfirmation = new UserRegisterConfirmation();
            $userRegisterConfirmation->setUser($entity);
            $userRegisterConfirmation->setToken(sha1(uniqid()));
            $this->managerRegistry->getManager()->persist($userRegisterConfirmation);
            $this->managerRegistry->getManager()->flush();

            if ($entity->getFirstname() && $entity->getLastname()) {
                $userFullName = $entity->getFirstname().' '.$entity->getLastname();
            } else {
                $userFullName = $entity->getEmail();
            }
            $loginUrl = $this->urlGeneratorInterface->generate(
                'app_user_user_register_confirmation',
                ['token' => $userRegisterConfirmation->getToken()],
                UrlGeneratorInterface::ABS_URL
            );

            $this->emailService->sendEmail(
                $entity->getEmail(),
                'Connexion à Aides-territoires',
                'emails/user/register.html.twig',
                [
                    'subject' => 'Connexion à Aides-territoires',
                    'userFullName' => $userFullName,
                    'loginUrl' => $loginUrl,
                ]
            );

            // Matomo trackGoal
            $this->matomoService->trackGoal($this->paramService->get('goal_register_id'));
        } catch (\Exception $e) {
            // notif admin
            /** @var User $entity */
            $entity = $args->getObject();
            $message = 'Erreur dans le postPersist User';
            if ($entity->getEmail()) {
                $message .= 'Pour le user '.$entity->getEmail();
            }
            $admin = $this->managerRegistry->getRepository(User::class)->findOneBy(['email' => $this->paramService->get('email_super_admin')]);
            $this->notificationService->addNotification($admin, 'Erreur postPersist User', $message);
        }
    }

    public function  onPreRemove(PreRemoveEventArgs $args) : void {
        /** @var User $user */
        $user = $args->getObject();

        foreach ($user->getOrganizations() as $organization) {
            // pas d'autre membres, on supprimera également l'organisation
            if (count($organization->getBeneficiairies()) === 1) {
                $this->managerRegistry->getManager()->remove($organization);
            }
        }
    }
}
