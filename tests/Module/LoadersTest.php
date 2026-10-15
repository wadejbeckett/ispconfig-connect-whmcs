<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Module;

use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\LoginFailedException;

/**
 * The ConfigOptions Loader functions (options 1 and 2 of the frozen order):
 * id => name maps built over the real stack, with a logout after every batch.
 */
final class LoadersTest extends ModuleTestCase
{
    public function testClientTemplateLoaderReturnsOnlyMasterTemplates(): void
    {
        $options = ispconfigconnect_ClientTemplateLoader($this->params());

        self::assertSame(
            [
                $this->masterTemplateId => 'Basic Hosting',
                $this->proTemplateId => 'Pro Hosting',
            ],
            $options
        );
        // The addon (type 'a') template must NOT be offered as a master.
        self::assertArrayNotHasKey($this->addonTemplateId, $options);
    }

    public function testClientTemplateLoaderLogsOutAfterwards(): void
    {
        ispconfigconnect_ClientTemplateLoader($this->params());

        self::assertSame(
            ['login', 'client_templates_get_all', 'logout'],
            $this->calledMethods()
        );
        self::assertSame(0, $this->fake->activeSessionCount());
    }

    public function testWebServerLoaderReturnsServerMap(): void
    {
        $secondServerId = $this->fake->addServer('web02.example.net');

        $options = ispconfigconnect_WebServerLoader($this->params());

        self::assertSame(
            [
                $this->webServerId => 'web01.example.net',
                $secondServerId => 'web02.example.net',
            ],
            $options
        );
        self::assertSame('logout', $this->calledMethods()[count($this->calledMethods()) - 1]);
    }

    public function testLoaderExceptionPropagatesForWhmcsInlineDisplay(): void
    {
        // WHMCS renders loader exceptions inline next to the dropdown - the
        // loader must rethrow, not swallow (spec 2.8).
        $this->expectException(LoginFailedException::class);

        ispconfigconnect_WebServerLoader($this->params(['serverpassword' => 'wrong-password']));
    }
}
