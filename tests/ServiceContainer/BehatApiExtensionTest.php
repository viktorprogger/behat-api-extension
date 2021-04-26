<?php

declare(strict_types=1);

namespace Imbo\BehatApiExtension\ServiceContainer;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Processor;

/**
 * @coversDefaultClass Imbo\BehatApiExtension\ServiceContainer\BehatApiExtension
 * @testdox Extension
 */
class BehatApiExtensionTest extends TestCase
{
    /**
     * @var BehatApiExtension
     */
    private $extension;

    /**
     * Set up the SUT
     */
    public function setup(): void
    {
        $this->extension = new BehatApiExtension();
    }

    /**
     * @covers ::getConfigKey
     * @covers ::configure
     */
    public function testCanBuildConfiguration()
    {
        $treeBuilder = new TreeBuilder();
        $rootNode = $treeBuilder->root($this->extension->getConfigKey());

        // Configure the root node builder
        $this->extension->configure($rootNode);

        // Process the configuration
        $config = (new Processor())->process($rootNode->getNode(true), []);

        $this->assertSame(
            [
                'apiClient' => [
                    'base_uri' => 'http://localhost:8080',
                ],
            ],
            $config
        );
    }

    /**
     * @covers ::configure
     */
    public function testCanOverrideDefaultValuesWhenBuildingConfiguration()
    {
        $treeBuilder = new TreeBuilder();
        $rootNode = $treeBuilder->root($this->extension->getConfigKey());

        // Configure the root node builder
        $this->extension->configure($rootNode);

        $baseUri = 'http://localhost:8888';
        $config = (new Processor())->process(
            $rootNode->getNode(true),
            [
                'api_extension' => [
                    'apiClient' => [
                        'base_uri' => $baseUri,
                    ],
                ],
            ]
        );

        $this->assertSame(
            [
                'apiClient' => [
                    'base_uri' => $baseUri,
                ],
            ],
            $config
        );
    }
}
