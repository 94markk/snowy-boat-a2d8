<?php
defined('ABSPATH') || exit;

interface DIP_Provider {
    public function id();
    public function label();
    public function is_configured(array $settings);
    public function callback_url();
    public function authorization_url(array $flow);
    public function authenticate($code, array $flow);
}

final class DIP_Provider_Registry {
    private $providers = [];

    public function register(DIP_Provider $provider) {
        $id = sanitize_key($provider->id());
        if (!$id || isset($this->providers[$id])) return false;
        $this->providers[$id] = $provider;
        return true;
    }

    public function get($id) {
        $id = sanitize_key($id);
        return $this->providers[$id] ?? null;
    }

    public function all() { return $this->providers; }

    public function configured(array $settings) {
        return array_filter($this->providers, static function ($provider) use ($settings) {
            return $provider->is_configured($settings);
        });
    }
}

final class DIP_Google_Provider implements DIP_Provider {
    private $settings;
    private $callback;
    public function __construct(array $settings, $callback) { $this->settings = $settings; $this->callback = esc_url_raw($callback); }
    public function id() { return 'google'; }
    public function label() { return 'Google'; }
    public function callback_url() { return $this->callback; }
    public function is_configured(array $settings) {
        return ($settings['enabled'] ?? 'no') === 'yes' && is_ssl() && !empty($settings['client_id']) && !empty($settings['client_secret']);
    }
    private function client() { return new DIP_Google($this->settings['client_id'], $this->settings['client_secret'], $this->callback, ($this->settings['google_prompt_select_account'] ?? 'yes') === 'yes'); }
    public function authorization_url(array $flow) { return $this->client()->authorization_url($flow); }
    public function authenticate($code, array $flow) { return $this->client()->authenticate($code, $flow); }
}

final class DIP_Microsoft_Provider implements DIP_Provider {
    private $settings;
    private $callback;
    public function __construct(array $settings, $callback) { $this->settings = $settings; $this->callback = esc_url_raw($callback); }
    public function id() { return 'microsoft'; }
    public function label() { return 'Microsoft'; }
    public function callback_url() { return $this->callback; }
    public function is_configured(array $settings) {
        return ($settings['microsoft_enabled'] ?? 'no') === 'yes' && is_ssl() && !empty($settings['microsoft_client_id']) && !empty($settings['microsoft_client_secret']);
    }
    private function client() { return new DIP_Microsoft($this->settings['microsoft_client_id'], $this->settings['microsoft_client_secret'], $this->settings['microsoft_tenant'] ?? 'common', $this->callback); }
    public function authorization_url(array $flow) { return $this->client()->authorization_url($flow); }
    public function authenticate($code, array $flow) { return $this->client()->authenticate($code, $flow); }
}
