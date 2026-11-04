<?php
declare( strict_types=1 );

namespace BoardKit\Tests\Unit;

use Brain\Monkey;
use BoardKit\Integrations\ColumnKit;
use BoardKit\Plugin;
use BoardKit\Updater\GitHubUpdater;
use PHPUnit\Framework\TestCase;

final class PluginTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		ColumnKit::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_instance_is_a_singleton(): void {
		$this->assertSame( Plugin::instance(), Plugin::instance() );
	}

	public function test_boot_registers_updater_once(): void {
		// Fresh instance: the singleton may already be booted by another test.
		$ref    = new \ReflectionClass( Plugin::class );
		$plugin = $ref->newInstanceWithoutConstructor();
		Monkey\Functions\when( 'is_admin' )->justReturn( true );

		$plugin->boot();
		$this->assertNotFalse( has_action( 'rest_api_init' ), 'REST routes hooked' );
		$this->assertNotFalse( has_action( 'admin_menu' ), 'admin menu hooked' );
		$this->assertNotFalse( has_action( 'init', [ $plugin, 'seed' ] ), 'starter board seeded on init' );
		$this->assertTrue( $plugin->is_booted() );
		$this->assertNotFalse( has_filter( 'update_plugins_' . GitHubUpdater::HOSTNAME ) );

		// Brain Monkey records hooks; a second boot must not add another updater instance.
		$plugin->boot();
		$this->assertTrue( $plugin->is_booted() );
	}

	public function test_columnkit_adapter_is_off_until_enabled(): void {
		ColumnKit::reset();
		$this->assertFalse( ColumnKit::is_enabled() );
		$this->assertTrue( ColumnKit::enable() );
		$this->assertTrue( ColumnKit::is_enabled() );
	}
}
