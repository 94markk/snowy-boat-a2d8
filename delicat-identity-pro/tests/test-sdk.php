<?php
class DIP_SDK_Test extends WP_UnitTestCase {
    public function test_sdk_version_is_stable() {
        $this->assertSame('1.0', DIP_SDK::API_VERSION);
    }
    public function test_report_excludes_secrets() {
        $json = wp_json_encode(DIP_SDK::sanitized_report());
        $this->assertStringNotContainsString('client_secret', strtolower($json));
        $this->assertStringNotContainsString('access_token', strtolower($json));
        $this->assertStringNotContainsString('refresh_token', strtolower($json));
    }
}
