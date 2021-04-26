<?php

declare(strict_types=1);

namespace Imbo\BehatApiExtension\ServiceContainer;

use Behat\Behat\Context\ServiceContainer\ContextExtension;
use Behat\Testwork\ServiceContainer\Extension as ExtensionInterface;
use Behat\Testwork\ServiceContainer\ExtensionManager;
use Imbo\BehatApiExtension\ArrayContainsComparator;
use Imbo\BehatApiExtension\Context\Initializer\ApiClientAwareInitializer;
use Imbo\BehatApiExtension\Context\Initializer\ArrayContainsComparatorAwareInitializer;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Behat API extension
 *
 * This extension provides a series of steps that can be used to easily test API's. The ApiContext
 * class also exposes the client, request and response objects so custom steps using the underlying
 * client can be implemented.
 *
 * @author Christer Edvartsen <cogo@starzinger.net>
 */
class BehatApiExtension implements ExtensionInterface
{
    /**
     * Service ID for the comparator
     *
     * @var string
     */
    public const COMPARATOR_SERVICE_ID = 'api_extension.comparator';

    /**
     * Service ID for the client initializer
     *
     * @var string
     */
    public const APICLIENT_INITIALIZER_SERVICE_ID = 'api_extension.api_client.context_initializer';

    /**
     * Service ID for the initializer
     *
     * @var string
     */
    public const COMPARATOR_INITIALIZER_SERVICE_ID = 'api_extension.comparator.context_initializer';

    /**
     * Config key for the extension
     *
     * @var string
     */
    public const CONFIG_KEY = 'api_extension';

    /**
     * {@inheritdoc}
     */
    public function getConfigKey(): string
    {
        return self::CONFIG_KEY;
    }

    /**
     * {@inheritdoc}
     * @codeCoverageIgnore
     */
    public function initialize(ExtensionManager $extensionManager): void
    {
        // Not used
    }

    /**
     * {@inheritdoc}
     */
    public function configure(ArrayNodeDefinition $builder): void
    {
        $builder
            ->children()
            ->arrayNode('apiClient')
            ->addDefaultsIfNotSet()
            ->ignoreExtraKeys(false)
            ->children()
            ->scalarNode('base_uri')
            ->isRequired()
            ->cannotBeEmpty()
            ->defaultValue('http://localhost:8080')
            ->end()
            ->end();
    }

    /**
     * {@inheritdoc}
     * @codeCoverageIgnore
     */
    public function load(ContainerBuilder $container, array $config): void
    {
        // Client initializer definition
        $clientInitializerDefinition = new Definition(
            ApiClientAwareInitializer::class,
            [
                $config['apiClient'],
            ]
        );
        $clientInitializerDefinition->addTag(ContextExtension::INITIALIZER_TAG);

        // Definition for the array contains comparator
        $comparatorDefinition = new Definition(ArrayContainsComparator::class);

        // Comparator initializer definition
        $comparatorInitializerDefinition = new Definition(
            ArrayContainsComparatorAwareInitializer::class,
            [
                new Reference(self::COMPARATOR_SERVICE_ID),
            ]
        );
        $comparatorInitializerDefinition->addTag(ContextExtension::INITIALIZER_TAG);

        // Add all definitions to the container
        $container->setDefinition(self::APICLIENT_INITIALIZER_SERVICE_ID, $clientInitializerDefinition);
        $container->setDefinition(self::COMPARATOR_SERVICE_ID, $comparatorDefinition);
        $container->setDefinition(self::COMPARATOR_INITIALIZER_SERVICE_ID, $comparatorInitializerDefinition);
    }

    /**
     * {@inheritdoc}
     * @codeCoverageIgnore
     */
    public function process(ContainerBuilder $container): void
    {
    }
}
