<?php

namespace Pterodactyl\Tests\Integration\Api\Mcp;

class AnnotationsTest extends McpIntegrationTestCase
{
    /**
     * The MCP spec defaults destructiveHint to true when the key is absent from a
     * tool's annotations, so a tool that ever emitted destructiveHint: false would be
     * telling a client that no confirmation is needed to run it. Every row in the
     * table is either a plain read hint or an explicit non-destructive-hint-false
     * pair; nothing else is allowed to appear.
     */
    public function testOnlyTwoAnnotationShapesEverAppearAndDestructiveHintIsNeverFalse(): void
    {
        [$user] = $this->generateTestAccount();
        $user->update(['root_admin' => true]);
        $this->actingAsApiKeyUser($user);

        $tools = $this->listTools();
        $this->assertNotEmpty($tools);

        foreach ($tools as $tool) {
            $annotations = $tool['annotations'];

            $this->assertNotSame(
                false,
                $annotations['destructiveHint'] ?? null,
                $tool['name'] . ' must never emit destructiveHint: false.'
            );

            $isReadOnlyShape = $annotations == ['readOnlyHint' => true];
            $isDestructiveShape = $annotations == ['readOnlyHint' => false, 'destructiveHint' => true];

            $this->assertTrue(
                $isReadOnlyShape || $isDestructiveShape,
                $tool['name'] . ' has an unexpected annotation shape: ' . json_encode($annotations)
            );
        }
    }

    /**
     * panel_client_servers_files_upload is a GET that hands back a signed Wings URL
     * accepting arbitrary file writes. The shape test above permits both annotation
     * shapes to appear in the table, so it would not by itself catch this row falling
     * back to the GET-implies-read-only shape.
     */
    public function testFileUploadToolCarriesTheDestructiveShape(): void
    {
        [$user] = $this->generateTestAccount();
        $user->update(['root_admin' => true]);
        $this->actingAsApiKeyUser($user);

        $tools = $this->listTools();
        $tool = collect($tools)->firstWhere('name', 'panel_client_servers_files_upload');

        $this->assertNotNull($tool, 'panel_client_servers_files_upload was not found in the tool listing.');
        $this->assertSame(['readOnlyHint' => false, 'destructiveHint' => true], $tool['annotations']);
    }
}
