<?php

namespace Scheb\TwoFactorBundle\Security\TwoFactor\Provider;

use Scheb\TwoFactorBundle\Security\TwoFactor\AuthenticationContextInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\AuthenticationHandlerInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvent;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvents;
use Scheb\TwoFactorBundle\Security\TwoFactor\Session\SessionFlagManager;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;

class TwoFactorProviderRegistry implements AuthenticationHandlerInterface
{
    /**
     * Manages session flags.
     *
     * @var SessionFlagManager
     */
    private $flagManager;

    /**
     * List of two-factor providers.
     *
     * @var array
     */
    private $providers;

    /**
     * Event dispatcher.
     *
     * @var EventDispatcherInterface
     */
    private $eventDispatcher;

    /**
     * Request parameter name used for code.
     *
     * @var string
     */
    private $authRequestParameter;

    public function __construct(SessionFlagManager $flagManager, EventDispatcherInterface $eventDispatcher, string $authRequestParameter, array $providers = [])
    {
        $this->flagManager = $flagManager;
        $this->providers   = $providers;
        $this->eventDispatcher = $eventDispatcher;
        $this->authRequestParameter = $authRequestParameter;
    }

    public function beginAuthentication(AuthenticationContextInterface $context): void
    {
        // Iterate over two-factor providers and begin the two-factor authentication process.
        /** @var TwoFactorProviderInterface $provider */
        foreach ($this->providers as $providerName => $provider) {
            $this->flagManager->setBegin($providerName, $context->getToken());

            if (!$provider->beginAuthentication($context)) {
                $this->flagManager->setAborted($providerName, $context->getToken());
            }
        }
    }

    public function requestAuthenticationCode(AuthenticationContextInterface $context): ?Response
    {
        $token = $context->getToken();

        // Iterate over two-factor providers and ask for completion
        /** @var TwoFactorProviderInterface $provider */
        foreach ($this->providers as $providerName => $provider) {
            if ($this->flagManager->isNotAuthenticated($providerName, $token)) {
                $response = $provider->requestAuthenticationCode($context);

                // Set authentication completed
                if ($context->isAuthenticated()) {
                    $this->eventDispatcher->dispatch(TwoFactorAuthenticationEvents::SUCCESS, new TwoFactorAuthenticationEvent($context->getRequest(), $context->getToken()));
                    $this->flagManager->setComplete($providerName, $token);
                } else if ($context->getRequest()->get($this->authRequestParameter) !== null) {
                    $this->eventDispatcher->dispatch(TwoFactorAuthenticationEvents::FAILURE, new TwoFactorAuthenticationEvent($context->getRequest(), $context->getToken()));
                }

                // Return response
                if ($response instanceof Response) {
                    return $response;
                }
            }
        }

        return null;
    }
}
