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
}
