<?php

namespace Tests\Concerns;

use App\Services\ContentModerator;

trait MocksContentModeration
{
    protected function setUp(): void
    {
        parent::setUp();

        // These suites isolate generation/persistence; ModerationTest exercises the real classifier boundary.
        $this->mock(ContentModerator::class)->shouldReceive('allows')->andReturn(true);
    }
}
