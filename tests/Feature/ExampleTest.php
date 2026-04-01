<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_returns_a_successful_response()
    {
        $response = $this->withoutVite()->get(route('home'));

        $response->assertOk();
    }
}
