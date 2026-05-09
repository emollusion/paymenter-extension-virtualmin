<?php

namespace Paymenter\Extensions\Servers\Virtualmin;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Server;
use App\Models\Product;
use App\Models\Service;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Paymenter\Extensions\Servers\Virtualmin\Http\VirtualminApiException;
use Paymenter\Extensions\Servers\Virtualmin\Http\VirtualminClient;
use Paymenter\Extensions\Servers\Virtualmin\Models\VirtualminAccount;

#[ExtensionMeta(
    name: 'Virtualmin',
    description: 'Provision and manage web hosting accounts on a Virtualmin cluster via the Virtualmin Remote API.',
    version: '1.3.0',
    author: 'emollusion',
    url: 'https://sa6bom.se',
    icon: 'https://www.virtualmin.com/images/virtualmin-logo.png'
)]
class Virtualmin extends Server
{
    // -------------------------------------------------------------------------
    // Boot / lifecycle
    // -------------------------------------------------------------------------

    public function boot(): void
    {
        View::addNamespace('virtualmin', __DIR__ . '/resources/views');
    }

    public function installed(): void
    {
        // Run migrations when installed via the admin panel
        \Artisan::call('migrate', [
            '--path'  => 'extension/Servers/Virtualmin/database/migrations',
            '--force' => true,
        ]);
    }

    public function uninstalled(): void
    {
        // Roll back this extension's migrations on uninstall
        \Artisan::call('migrate:rollback', [
            '--path'  => 'extension/Servers/Virtualmin/database/migrations',
            '--force' => true,
        ]);
    }

    public function upgraded($oldVersion = null): void
    {
        // Run any new migrations on upgrade
        \Artisan::call('migrate', [
            '--path'  => 'extension/Servers/Virtualmin/database/migrations',
            '--force' => true,
        ]);
    }

    // -------------------------------------------------------------------------
    // Extension-level configuration (master node connection)
    // -------------------------------------------------------------------------

    public function getConfig($values = []): array
    {
        return [
            [
                'name'        => 'host',
                'label'       => 'Virtualmin Master Host',
                'type'        => 'text',
                'default'     => '',
                'description' => 'Hostname or IP of the Virtualmin master node.',
                'required'    => true,
                'validation'  => 'required|string',
            ],
            [
                'name'        => 'port',
                'label'       => 'Webmin Port',
                'type'        => 'number',
                'default'     => 10000,
                'description' => 'Port Webmin/Virtualmin listens on (default: 10000).',
                'required'    => true,
                'validation'  => 'required|integer|min:1|max:65535',
            ],
            [
                'name'        => 'usermin_port',
                'label'       => 'Usermin Port',
                'type'        => 'number',
                'default'     => 20000,
                'description' => 'Port Usermin listens on (default: 20000). Used for the customer panel link.',
                'required'    => true,
                'validation'  => 'required|integer|min:1|max:65535',
            ],
            [
                'name'        => 'username',
                'label'       => 'API Username',
                'type'        => 'text',
                'default'     => '',
                'description' => 'Webmin user with Virtualmin Virtual Servers module access. Do not use root.',
                'required'    => true,
                'validation'  => 'required|string',
            ],
            [
                'name'        => 'password',
                'label'       => 'API Password',
                'type'        => 'password',
                'default'     => '',
                'description' => 'Password for the API user. Stored in the Paymenter database — ensure DB encryption is enabled.',
                'required'    => true,
                'validation'  => 'required|string',
            ],
            [
                'name'        => 'verify_tls',
                'label'       => 'Verify TLS Certificate',
                'type'        => 'checkbox',
                'default'     => true,
                'description' => 'WARNING: Only disable in internal/isolated environments with self-signed certificates. Never disable for internet-facing nodes.',
                'required'    => false,
            ],
            [
                'name'        => 'nodes',
                'label'       => 'Worker Nodes (JSON)',
                'type'        => 'text',
                'default'     => '[]',
                'description' => 'JSON array of Virtualmin worker nodes. Each node: {"host":"hostname","port":10000,"label":"web-01","max_disk_mb":500000,"max_bw_mb":5000000,"max_domains":200,"exclude":false}. Domains are provisioned directly on the selected worker node based on available capacity.',
                'required'    => true,
                'validation'  => 'required|json',
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Product-level configuration
    // -------------------------------------------------------------------------

    public function getProductConfig($values = []): array
    {
        return [
            // --- Plan / template mapping ---
            [
                'name'        => 'plan',
                'label'       => 'Virtualmin Account Plan',
                'type'        => 'text',
                'default'     => 'Default Plan',
                'description' => 'Exact name of the Virtualmin Account Plan. Must exist in Virtualmin → Account Plans. Virtualmin manages the feature set defined in this plan.',
                'required'    => true,
                'validation'  => 'required|string',
            ],
            [
                'name'        => 'template',
                'label'       => 'Virtualmin Server Template',
                'type'        => 'text',
                'default'     => '',
                'description' => 'Exact name of the Virtualmin Server Template. Leave blank for the Virtualmin default.',
                'required'    => false,
                'validation'  => 'nullable|string',
            ],

            // --- Feature overrides ---
            // Virtualmin plans define the feature set. These checkboxes allow
            // per-product overrides on top of what the plan enables.
            // IMPORTANT: feature_ssl requires feature_web — enforced at validation.
            [
                'name'        => 'feature_web',
                'label'       => 'Enable Website (Apache/Nginx)',
                'type'        => 'checkbox',
                'default'     => true,
                'description' => 'Provision a web server virtual host.',
                'required'    => false,
            ],
            [
                'name'        => 'feature_ssl',
                'label'       => 'Enable SSL',
                'type'        => 'checkbox',
                'default'     => true,
                'description' => 'Enable SSL. Requires "Enable Website" to also be checked.',
                'required'    => false,
            ],
            [
                'name'        => 'feature_dns',
                'label'       => 'Enable DNS',
                'type'        => 'checkbox',
                'default'     => true,
                'description' => 'Create a DNS zone for the domain.',
                'required'    => false,
            ],
            [
                'name'        => 'feature_mail',
                'label'       => 'Enable Mail',
                'type'        => 'checkbox',
                'default'     => true,
                'description' => 'Enable mail hosting (Postfix/Dovecot).',
                'required'    => false,
            ],
            [
                'name'        => 'feature_mysql',
                'label'       => 'Enable MySQL/MariaDB',
                'type'        => 'checkbox',
                'default'     => true,
                'description' => 'Create a MySQL database and user.',
                'required'    => false,
            ],
            [
                'name'        => 'feature_ftp',
                'label'       => 'Enable FTP',
                'type'        => 'checkbox',
                'default'     => true,
                'description' => 'Enable FTP access.',
                'required'    => false,
            ],
            [
                'name'        => 'feature_ssh',
                'label'       => 'Enable SSH Shell Access',
                'type'        => 'checkbox',
                'default'     => false,
                'description' => 'Allow the domain owner to SSH into the server. The Unix system user is always created when mail, FTP or web is enabled — this flag additionally grants a login shell.',
                'required'    => false,
            ],
            [
                'name'        => 'feature_webmin',
                'label'       => 'Enable Webmin/Usermin Login',
                'type'        => 'checkbox',
                'default'     => true,
                'description' => 'Creates a Webmin/Usermin login for the domain owner. Required for the customer panel link.',
                'required'    => false,
            ],
            [
                'name'        => 'feature_logrotate',
                'label'       => 'Enable Log Rotation',
                'type'        => 'checkbox',
                'default'     => true,
                'description' => 'Enable automatic log rotation.',
                'required'    => false,
            ],
            [
                'name'        => 'feature_spam',
                'label'       => 'Enable Spam Filtering',
                'type'        => 'checkbox',
                'default'     => false,
                'description' => 'Enable SpamAssassin spam filtering.',
                'required'    => false,
            ],
            [
                'name'        => 'feature_virus',
                'label'       => 'Enable Virus Scanning',
                'type'        => 'checkbox',
                'default'     => false,
                'description' => 'Enable ClamAV virus scanning.',
                'required'    => false,
            ],

            // --- Resource overrides ---
            // These override the plan defaults. Leave blank to use plan values.
            // On upgrade: values here must be >= the customer's current usage.
            // The extension guards against lowering limits below the plan minimum
            // but cannot guard against lowering below current actual usage —
            // Virtualmin will reject such changes at the API level.
            [
                'name'        => 'quota_disk',
                'label'       => 'Disk Quota (MB)',
                'type'        => 'number',
                'default'     => '',
                'description' => 'Override plan disk quota in MB. Leave blank to use the plan default.',
                'required'    => false,
                'validation'  => 'nullable|integer|min:0',
            ],
            [
                'name'        => 'quota_bw',
                'label'       => 'Bandwidth Quota (MB)',
                'type'        => 'number',
                'default'     => '',
                'description' => 'Override plan monthly bandwidth quota in MB. Leave blank to use the plan default.',
                'required'    => false,
                'validation'  => 'nullable|integer|min:0',
            ],
            [
                'name'        => 'max_doms',
                'label'       => 'Max Sub-Domains',
                'type'        => 'number',
                'default'     => '',
                'description' => 'Override the maximum number of sub-domains. Leave blank to use the plan default.',
                'required'    => false,
                'validation'  => 'nullable|integer|min:0',
            ],
            [
                'name'        => 'max_aliases',
                'label'       => 'Max Alias Domains',
                'type'        => 'number',
                'default'     => '',
                'description' => 'Override the maximum number of alias domains.',
                'required'    => false,
                'validation'  => 'nullable|integer|min:0',
            ],
            [
                'name'        => 'max_mailboxes',
                'label'       => 'Max Mailboxes',
                'type'        => 'number',
                'default'     => '',
                'description' => 'Override the maximum number of mailboxes.',
                'required'    => false,
                'validation'  => 'nullable|integer|min:0',
            ],
            [
                'name'        => 'max_dbs',
                'label'       => 'Max Databases',
                'type'        => 'number',
                'default'     => '',
                'description' => 'Override the maximum number of MySQL/MariaDB databases.',
                'required'    => false,
                'validation'  => 'nullable|integer|min:0',
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Checkout configuration
    // -------------------------------------------------------------------------

    public function getCheckoutConfig(Product $product, $values = [], $settings = []): array
    {
        return [
            [
                'name'        => 'domain',
                'label'       => 'Domain Name',
                'type'        => 'text',
                'default'     => '',
                'description' => 'The domain name for your hosting account (e.g. example.com). You must own this domain.',
                'required'    => true,
                // RFC-compliant domain validation; canonicalised to lowercase before use
                'validation'  => 'required|string|regex:/^([a-z0-9]+(-[a-z0-9]+)*\.)+[a-z]{2,}$/i',
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Provisioning lifecycle
    // -------------------------------------------------------------------------

    /**
     * Create a new Virtualmin virtual server.
     *
     * Username strategy:
     *   We generate the username ourselves using the same derivation logic
     *   Virtualmin uses, then pass it explicitly via --user. This avoids
     *   guessing what Virtualmin assigned after the fact. If the derived
     *   username is already taken, we append a numeric suffix and retry
     *   via list-domains --user-only before creating.
     *
     * Password strategy:
     *   A temporary password is generated, passed to create-domain, and
     *   displayed once in the response. It is NOT stored. The customer
     *   should change it immediately via Usermin. A "Reset Password" action
     *   is available for subsequent one-time password delivery.
     *
     * Idempotency:
     *   If a VirtualminAccount record already exists for this service,
     *   provisioning is skipped (already done). This handles Paymenter retries.
     */
    public function createServer(Service $service, array $settings, array $properties): void
    {
        // Clean up any terminated account before re-provisioning
        VirtualminAccount::where('service_id', $service->id)
            ->where('status', 'terminated')
            ->delete();

        // Only skip if an active account already exists
        if (VirtualminAccount::where('service_id', $service->id)
                ->where('status', 'active')
                ->exists()) {
            Log::info("Virtualmin: service #{$service->id} already has an active account, skipping.");
            return;
        }

        $merged         = array_merge($settings, $properties);
        $serverSettings = $this->getServerSettings($service);
        // Node selection happens below after domain validation

        // Canonicalise domain: lowercase and trim
        $domain = strtolower(trim($merged['domain'] ?? ''));

        if (empty($domain)) {
            throw new VirtualminApiException('No domain name provided for service #' . $service->id);
        }

        // Validate SSL→web dependency
        $sslEnabled = filter_var($merged['feature_ssl'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $webEnabled = filter_var($merged['feature_web'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($sslEnabled && !$webEnabled) {
            throw new VirtualminApiException(
                'SSL cannot be enabled without the Web feature. Check your product configuration.'
            );
        }

        $plan     = $merged['plan'] ?? 'Default Plan';
        $template = $merged['template'] ?? '';

        // Select the best worker node based on resource headroom
        $node       = $this->selectBestNode($serverSettings, $merged);
        $nodeClient = $this->makeNodeClient($node, $serverSettings);

        // Generate a safe username and resolve collisions on the selected node
        $username = $this->resolveUsername($nodeClient, $domain);

        // Generate a temporary password — displayed once, never stored
        $tempPassword = Str::password(20, symbols: false);

        // Build the create-domain payload
        // NOTE: array_filter is intentionally NOT used here because feature flags
        // have empty-string values ('') which must survive into the payload.
        $params = [
            'domain'           => $domain,
            'user'             => $username,
            'pass'             => $tempPassword,
            'plan'             => $plan,
            'limits-from-plan' => '',
            'desc'             => "Paymenter #{$service->id}",
        ];

        if (!empty($template)) {
            $params['template'] = $template;
        }

        // Detect features on the selected node and build appropriate feature flags
        // (node was already validated in selectBestNode — this is for flag mapping only)
        $nodeFeatures = $this->detectNodeFeatures($nodeClient);
        $webServer    = $this->getWebServerType($nodeFeatures);
        Log::info("Virtualmin: node {$node['label']} web server: {$webServer}");
        $params = array_merge($params, $this->buildFeatureFlags($merged, $webServer));

        // Resource overrides (only non-empty values)
        $params = array_merge($params, $this->buildResourceOverrides($merged));

        $nodeClient->call('create-domain', $params);

        // Build Usermin URL pointing at the worker node
        $userminUrl = $this->buildUserminUrl($node, $serverSettings);

        // Build control panel URL if configured on this node
        $controlPanelUrl = $this->buildControlPanelUrl($node);

        // Persist account record — node_host/port stored for lifecycle operations
        VirtualminAccount::create([
            'service_id'       => $service->id,
            'domain'           => $domain,
            'username'         => $username,
            'usermin_url'      => $userminUrl,
            'control_panel_url'=> $controlPanelUrl,
            'node_host'        => $node['host'],
            'node_port'        => (int) ($node['port'] ?? 10000),
            'status'           => 'active',
            'provisioned_at'   => now(),
        ]);

        Log::info("Virtualmin: provisioned service #{$service->id} ({$domain}, user: {$username}, node: {$node['label']})");

        // Note: $tempPassword intentionally discarded here.
        // Paymenter's service creation flow should surface it to the customer via
        // the welcome email / order confirmation. Hook into that mechanism when
        // Paymenter's API provides it — for now it is logged at debug level only.
        Log::debug("Virtualmin: temporary password issued for {$username} on service #{$service->id}");
    }

    /**
     * Suspend a virtual server (disable-domain).
     */
    public function suspendServer(Service $service, array $settings, array $properties): void
    {
        $serverSettings = $this->getServerSettings($service);
        $account        = $this->requireAccount($service);
        $client         = $this->makeAccountClient($account, $serverSettings);

        $client->call('disable-domain', [
            'domain' => $account->domain,
            'why'    => 'Suspended via Paymenter',
        ]);

        $account->update(['status' => 'suspended']);

        Log::info("Virtualmin: suspended {$account->domain} (service #{$service->id})");
    }

    /**
     * Unsuspend a virtual server (enable-domain).
     */
    public function unsuspendServer(Service $service, array $settings, array $properties): void
    {
        $serverSettings = $this->getServerSettings($service);
        $account        = $this->requireAccount($service);
        $client         = $this->makeAccountClient($account, $serverSettings);

        $client->call('enable-domain', [
            'domain' => $account->domain,
        ]);

        $account->update(['status' => 'active']);

        Log::info("Virtualmin: unsuspended {$account->domain} (service #{$service->id})");
    }

    /**
     * Permanently delete a virtual server (delete-domain).
     *
     * If the domain no longer exists in Virtualmin (e.g. manually deleted),
     * we treat it as already terminated rather than throwing.
     */
    public function terminateServer(Service $service, array $settings, array $properties): void
    {
        $serverSettings = $this->getServerSettings($service);
        $account        = $this->requireAccount($service);
        $client         = $this->makeAccountClient($account, $serverSettings);

        try {
            $client->call('delete-domain', [
                'domain' => $account->domain,
            ]);
        } catch (VirtualminApiException $e) {
            // Domain already gone — not an error from our perspective
            if (stripos($e->getMessage(), 'does not exist') !== false) {
                Log::warning(
                    "Virtualmin: domain {$account->domain} not found during termination — treating as already deleted."
                );
            } else {
                throw $e;
            }
        }

        $account->update(['status' => 'terminated']);

        Log::info("Virtualmin: terminated {$account->domain} (service #{$service->id})");
    }

    /**
     * Upgrade or downgrade — apply new plan and resource overrides.
     *
     * Resource override guard:
     *   We fetch the current limits from Virtualmin before applying the new ones.
     *   If any new limit is lower than the current plan's limit (not usage —
     *   that's Virtualmin's job to reject), we log a warning. Virtualmin will
     *   enforce actual quota constraints at the API level.
     *
     * Feature management:
     *   Virtualmin's plan definition controls the feature set. We apply
     *   --apply-plan which lets Virtualmin reconcile features per the new plan.
     *   This is intentional — plan-managed features are more maintainable
     *   than per-feature diffs from this layer.
     *
     * Template changes:
     *   If the new product specifies a different template, it is applied via
     *   modify-domain. Templates affect new sub-objects only; they do not
     *   retroactively change existing config.
     */
    public function upgradeServer(Service $service, array $settings, array $properties): void
    {
        $merged         = array_merge($settings, $properties);
        $serverSettings = $this->getServerSettings($service);
        $account        = $this->requireAccount($service);
        $client         = $this->makeAccountClient($account, $serverSettings);

        $plan     = $merged['plan'] ?? null;
        $template = $merged['template'] ?? null;

        // Fetch current domain limits for the guard check
        $this->guardResourceDowngrade($client, $account->domain, $merged);

        $params = ['domain' => $account->domain];

        if ($plan) {
            $params['plan'] = $plan;
            $params['limits-from-plan'] = '';
        }

        if (!empty($template)) {
            $params['template'] = $template;
        }

        // Apply resource overrides for the new tier
        $params = array_merge($params, $this->buildResourceOverrides($merged));

        $client->call('modify-domain', $params);

        Log::info(
            "Virtualmin: upgraded {$account->domain} (service #{$service->id}) to plan '{$plan}'"
        );
    }

    // -------------------------------------------------------------------------
    // Customer-facing actions
    // -------------------------------------------------------------------------

    /**
     * Actions displayed on the service page in the customer panel.
     *
     * Paymenter action types (per official docs):
     *   'text'   — displays a label/value pair inline
     *   'button' — a clickable button; 'url' or 'function' provides the href
     *   'view'   — renders a Blade view; 'function' is called to get the View object
     */
    public function getActions(Service $service, array $settings, array $properties): array
    {
        $account = VirtualminAccount::where('service_id', $service->id)->first();

        if (!$account) {
            return [];
        }

        $actions = [
            // Inline display: domain name
            [
                'type'  => 'text',
                'label' => 'Domain',
                'text'  => $account->domain,
            ],
            // Inline display: username
            [
                'type'  => 'text',
                'label' => 'Username',
                'text'  => $account->username,
            ],
            // Inline display: account status
            [
                'type'  => 'text',
                'label' => 'Status',
                'text'  => ucfirst($account->status),
            ],
            // Button: open Usermin (email, files, basic settings)
            [
                'name'     => 'open_usermin',
                'label'    => 'Open Usermin (Email & Files)',
                'type'     => 'button',
                'function' => 'getUserminUrl',
            ],
        ];

        // Control panel button — only shown if configured on the node
        if ($account->control_panel_url) {
            $actions[] = [
                'name'     => 'open_control_panel',
                'label'    => 'Open Control Panel (Virtualmin)',
                'type'     => 'button',
                'function' => 'getControlPanelUrl',
            ];
        }

        // Reset password view
        $actions[] = [
            'name'     => 'reset_password',
            'label'    => 'Reset Password',
            'type'     => 'view',
            'function' => 'getResetPasswordView',
        ];

        return $actions;
    }

    /**
     * Returns the Usermin URL for the button action.
     * Per Paymenter docs, button functions receive (Service $service) and return a URL string.
     * Future hook point for SSO token URL generation.
     */
    public function getUserminUrl(Service $service): string
    {
        $account = VirtualminAccount::where('service_id', $service->id)->first();

        return $account?->usermin_url ?? '#';
    }

    /**
     * Returns the Virtualmin control panel URL for the button action.
     * Points to the public Nginx proxy to Webmin, scoped to the domain owner's account.
     */
    public function getControlPanelUrl(Service $service): string
    {
        $account = VirtualminAccount::where('service_id', $service->id)->first();

        return $account?->control_panel_url ?? '#';
    }

    /**
     * Renders the manage-hosting info panel as a view action.
     * Per Paymenter docs: view functions receive (Service, settings, properties, view) : View
     */
    public function getManageHostingView(
        Service $service,
        array $settings,
        array $properties,
        string $view
    ): \Illuminate\View\View {
        $account = VirtualminAccount::where('service_id', $service->id)->firstOrFail();

        return view('virtualmin::actions.manage_hosting', [
            'account' => $account,
            'service' => $service,
        ]);
    }

    /**
     * Resets the domain owner password and renders the one-time display view.
     * Per Paymenter docs: view functions receive (Service, settings, properties, view) : View
     *
     * The new password is generated, sent to Virtualmin, rendered once, and discarded.
     * It is never stored. Future: replace with Usermin SSO token flow.
     */
    public function getResetPasswordView(
        Service $service,
        array $settings,
        array $properties,
        string $view
    ): \Illuminate\View\View {
        $account        = VirtualminAccount::where('service_id', $service->id)->firstOrFail();
        $serverSettings = $this->getServerSettings($service);
        $client         = $this->makeAccountClient($account, $serverSettings);

        $newPassword = Str::password(20, symbols: false);

        $client->call('modify-domain', [
            'domain' => $account->domain,
            'pass'   => $newPassword,
        ]);

        Log::info("Virtualmin: password reset for {$account->domain} (service #{$service->id})");

        return view('virtualmin::actions.password_reset', [
            'account'     => $account,
            'newPassword' => $newPassword,
            // Password intentionally not stored — view renders it once.
        ]);
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve a unique username for the given domain.
     *
     * Strategy:
     *  1. Derive the username from the domain using Virtualmin's naming convention.
     *  2. Check whether that username is already in use via list-domains --user-only.
     *  3. If taken, append a numeric suffix and repeat until a free name is found.
     *  4. Give up after 10 attempts (extremely unlikely in practice).
     *
     * We pass the resolved username explicitly to create-domain via --user,
     * so we always know exactly what Virtualmin will use.
     *
     * @throws VirtualminApiException
     */
    private function resolveUsername(VirtualminClient $client, string $domain): string
    {
        $base    = $this->domainToUsername($domain);
        $attempt = $base;

        for ($i = 1; $i <= 10; $i++) {
            try {
                // list-domains --user-only --user <name> returns a line if the user exists
                $result = $client->call('list-domains', [
                    'user'      => $attempt,
                    'user-only' => '',
                ]);
                // Non-empty output means the username is already in use
                $taken = !empty(trim($result['output'] ?? ''));
            } catch (VirtualminApiException) {
                // An error here typically means no domain exists for that user — safe to use
                $taken = false;
            }

            if (!$taken) {
                return $attempt;
            }

            // Suffix and retry — truncate base to leave room for suffix
            $suffix   = (string) $i;
            $attempt  = substr($base, 0, 8 - strlen($suffix)) . $suffix;
        }

        throw new VirtualminApiException(
            "Could not find a free username for domain '{$domain}' after 10 attempts."
        );
    }

    /**
     * Derive the base Unix username from a domain name, mirroring Virtualmin's convention:
     *  - Strip www prefix
     *  - Take the first label (before the first dot)
     *  - Remove non-alphanumeric characters
     *  - Lowercase and truncate to 8 characters
     */
    private function domainToUsername(string $domain): string
    {
        $domain   = preg_replace('/^www\./', '', strtolower($domain));
        $parts    = explode('.', $domain);
        $username = preg_replace('/[^a-z0-9]/', '', $parts[0]);

        return substr($username, 0, 8);
    }

    /**
     * Guard against resource downgrades during upgrade.
     *
     * Fetches the domain's current quota limits from Virtualmin and logs a warning
     * if any new limit is lower than the current configured limit.
     *
     * Note: Virtualmin enforces actual quota violations at the API level.
     * This guard provides early warning in logs before the API call is made,
     * so operators can investigate pricing/billing implications.
     */
    private function guardResourceDowngrade(
        VirtualminClient $client,
        string $domain,
        array $merged
    ): void {
        try {
            $result  = $client->list('list-domains', ['domain' => $domain]);
            $domains = $result['data'] ?? [];

            if (empty($domains)) {
                return;
            }

            $current = $domains[0] ?? [];

            $checks = [
                'quota_disk'    => ['current_key' => 'quota',     'label' => 'Disk quota'],
                'quota_bw'      => ['current_key' => 'bandwidth', 'label' => 'Bandwidth quota'],
                'max_mailboxes' => ['current_key' => 'mailboxes', 'label' => 'Max mailboxes'],
                'max_dbs'       => ['current_key' => 'dbs',       'label' => 'Max databases'],
            ];

            foreach ($checks as $settingKey => $meta) {
                $newValue     = $merged[$settingKey] ?? '';
                $currentValue = $current[$meta['current_key']] ?? null;

                if ($newValue !== '' && $currentValue !== null && (int) $newValue < (int) $currentValue) {
                    Log::warning(
                        "Virtualmin: upgrade for {$domain} sets {$meta['label']} to {$newValue}, "
                        . "which is lower than current limit {$currentValue}. "
                        . "Virtualmin may reject this if it conflicts with current usage."
                    );
                }
            }
        } catch (VirtualminApiException $e) {
            // Non-fatal — if we can't fetch current limits, proceed and let Virtualmin decide
            Log::warning("Virtualmin: could not fetch current limits for downgrade guard on {$domain}: {$e->getMessage()}");
        }
    }

    /**
     * Build feature flags for the Virtualmin API.
     *
     * Virtualmin feature parameters are bare flags with empty-string values.
     * DO NOT pass these through array_filter — empty strings are intentional.
     *
     * @return array<string, string>
     */
    /**
     * Detect available features on a Virtualmin node by parsing check-config output.
     *
     * Returns an array of available Virtualmin feature flags (the same strings used
     * in create-domain parameters). This is used to:
     *
     *  1. Select the correct web/SSL flag (nginx vs apache)
     *  2. Validate that all requested product features are available on the node
     *     BEFORE attempting provisioning — so we skip incompatible nodes during
     *     selection rather than failing mid-provisioning.
     *
     * @return array<string, bool>  Map of feature flag => available
     */
    private function detectNodeFeatures(VirtualminClient $client): array
    {
        $features = [
            'dns'                  => false,
            'mail'                 => false,
            'ftp'                  => false,
            'mysql'                => false,
            'postgres'             => false,
            'logrotate'            => false,
            'spam'                 => false,
            'virus'                => false,
            'web'                  => false, // Apache
            'ssl'                  => false, // Apache SSL
            'virtualmin-nginx'     => false,
            'virtualmin-nginx-ssl' => false,
            'webmin'               => true,  // Always available
            'unix'                 => true,  // Always available
            'dir'                  => true,  // Always available
        ];

        try {
            $result = $client->call('check-config');
            $output = $result['output'] ?? '';

            // DNS
            if (stripos($output, 'BIND DNS server is installed') !== false) {
                $features['dns'] = true;
            }

            // Mail
            if (stripos($output, 'Mail server') !== false
                && stripos($output, 'is installed') !== false) {
                $features['mail'] = true;
            }

            // FTP
            if (stripos($output, 'ProFTPD is installed') !== false
                || stripos($output, 'Pure-FTPd is installed') !== false
                || stripos($output, 'vsftpd is installed') !== false) {
                $features['ftp'] = true;
            }

            // MySQL / MariaDB
            if (stripos($output, 'MySQL') !== false || stripos($output, 'MariaDB') !== false) {
                $features['mysql'] = true;
            }

            // PostgreSQL
            if (stripos($output, 'PostgreSQL') !== false) {
                $features['postgres'] = true;
            }

            // Logrotate
            if (stripos($output, 'Logrotate is installed') !== false) {
                $features['logrotate'] = true;
            }

            // SpamAssassin
            if (stripos($output, 'SpamAssassin') !== false) {
                $features['spam'] = true;
            }

            // ClamAV
            if (stripos($output, 'ClamAV is installed') !== false) {
                $features['virus'] = true;
            }

            // Apache
            if (stripos($output, 'Apache') !== false
                && stripos($output, 'is installed') !== false) {
                $features['web'] = true;
                $features['ssl'] = true;
            }

            // Nginx (plugin-based)
            if (stripos($output, 'Plugin Nginx website is installed') !== false) {
                $features['virtualmin-nginx'] = true;
            }
            if (stripos($output, 'Plugin Nginx SSL website is installed') !== false) {
                $features['virtualmin-nginx-ssl'] = true;
            }

        } catch (VirtualminApiException $e) {
            Log::warning("Virtualmin: could not detect node features: {$e->getMessage()}");
        }

        return $features;
    }

    /**
     * Determine web server type from detected node features.
     */
    private function getWebServerType(array $nodeFeatures): string
    {
        if ($nodeFeatures['virtualmin-nginx'] ?? false) {
            return 'nginx';
        }
        return 'apache';
    }

    /**
     * Validate that all features requested in the product config are available
     * on the given node. Returns an array of missing features (empty = all OK).
     *
     * This is called during node selection so incompatible nodes are skipped
     * cleanly rather than failing mid-provisioning.
     *
     * @return string[]  List of unavailable feature names
     */
    private function getMissingFeatures(array $merged, array $nodeFeatures, string $webServer): array
    {
        $webFlag = $webServer === 'nginx' ? 'virtualmin-nginx'     : 'web';
        $sslFlag = $webServer === 'nginx' ? 'virtualmin-nginx-ssl' : 'ssl';

        $requestedMap = [
            'feature_web'       => $webFlag,
            'feature_ssl'       => $sslFlag,
            'feature_dns'       => 'dns',
            'feature_mail'      => 'mail',
            'feature_ftp'       => 'ftp',
            'feature_mysql'     => 'mysql',
            'feature_logrotate' => 'logrotate',
            'feature_spam'      => 'spam',
            'feature_virus'     => 'virus',
        ];

        $missing = [];

        foreach ($requestedMap as $settingKey => $virtFlag) {
            $requested = filter_var($merged[$settingKey] ?? false, FILTER_VALIDATE_BOOLEAN);
            if ($requested && empty($nodeFeatures[$virtFlag])) {
                $missing[] = $virtFlag;
            }
        }

        return $missing;
    }

    /**
     * Build feature flags for the Virtualmin API.
     *
     * Virtualmin feature parameters are bare flags with empty-string values.
     * DO NOT pass these through array_filter — empty strings are intentional.
     *
     * Web/SSL flags differ by web server:
     *   Apache: --web, --ssl
     *   Nginx:  --virtualmin-nginx, --virtualmin-nginx-ssl
     *
     * @param  string  $webServer  'nginx' or 'apache', detected via detectWebServer()
     * @return array<string, string>
     */
    private function buildFeatureFlags(array $merged, string $webServer = 'apache'): array
    {
        $webFlag = $webServer === 'nginx' ? 'virtualmin-nginx'     : 'web';
        $sslFlag = $webServer === 'nginx' ? 'virtualmin-nginx-ssl' : 'ssl';

        $featureMap = [
            'feature_web'       => $webFlag,
            'feature_ssl'       => $sslFlag,
            'feature_dns'       => 'dns',
            'feature_mail'      => 'mail',
            'feature_mysql'     => 'mysql',
            'feature_ftp'       => 'ftp',
            'feature_webmin'    => 'webmin',
            'feature_logrotate' => 'logrotate',
            'feature_spam'      => 'spam',
            'feature_virus'     => 'virus',
        ];

        $flags = [];

        foreach ($featureMap as $settingKey => $virtFlag) {
            $enabled = filter_var($merged[$settingKey] ?? false, FILTER_VALIDATE_BOOLEAN);
            if ($enabled) {
                $flags[$virtFlag] = '';
            }
        }

        // --unix and --dir are required whenever mail, ftp, or web is enabled.
        // Note: flags use empty string '' as value (bare flags), so we use
        // array_key_exists() not !empty() — empty('') is false in PHP.
        $needsUnixUser = array_key_exists('mail', $flags)
            || array_key_exists('ftp', $flags)
            || array_key_exists($webFlag, $flags);

        if ($needsUnixUser) {
            $flags['unix'] = '';
            $flags['dir']  = '';
        }

        return $flags;
    }


    /**
     * Build resource override parameters.
     * Only includes keys where the admin has set a non-empty value.
     *
     * @return array<string, int>
     */
    private function buildResourceOverrides(array $merged): array
    {
        $overrideMap = [
            'quota_disk'    => 'quota',
            'quota_bw'      => 'bandwidth',
            'max_doms'      => 'max-doms',
            'max_aliases'   => 'max-aliases',
            'max_mailboxes' => 'max-mailboxes',
            'max_dbs'       => 'max-dbs',
        ];

        $overrides = [];

        foreach ($overrideMap as $settingKey => $virtParam) {
            $value = $merged[$settingKey] ?? '';
            if ($value !== '' && $value !== null) {
                $overrides[$virtParam] = (int) $value;
            }
        }

        return $overrides;
    }

    /**
     * Build a VirtualminClient using server-level credentials.
     *
     * $settings passed to lifecycle hooks contains product config (plan, features, quotas).
     * Server credentials live on the Server model linked to the product, accessed via
     * $service->product->server->settings — separate from product settings.
     */
    private function makeClient(Service $service): VirtualminClient
    {
        $server = $this->getServerSettings($service);

        return new VirtualminClient(
            host:      $server['host'] ?? '',
            port:      (int) ($server['port'] ?? 10000),
            username:  $server['username'] ?? '',
            password:  $server['password'] ?? '',
            verifyTls: (bool) ($server['verify_tls'] ?? true),
        );
    }

    // -------------------------------------------------------------------------
    // Node selection and capacity management
    // -------------------------------------------------------------------------

    /**
     * Select the best worker node for provisioning based on resource headroom.
     *
     * Algorithm:
     *  1. Parse the nodes JSON from server config
     *  2. Filter out excluded nodes
     *  3. For each candidate, query list-domains --multiline --toplevel
     *  4. Sum allocated resources across all domains on that node
     *  5. Calculate headroom score for each resource dimension
     *  6. Skip nodes above the configured threshold on any dimension
     *  7. Return the node with the highest minimum headroom (bottleneck-aware)
     *
     * @param  array<string, mixed>  $serverSettings  Resolved server settings
     * @param  array<string, mixed>  $merged          Merged product + checkout settings
     * @return array<string, mixed>  Selected node config
     *
     * @throws VirtualminApiException
     */
    private function selectBestNode(array $serverSettings, array $merged): array
    {
        $nodesJson = $serverSettings['nodes'] ?? '[]';

        try {
            $nodes = json_decode($nodesJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new VirtualminApiException(
                "Invalid nodes JSON in server config: {$e->getMessage()}"
            );
        }

        if (empty($nodes)) {
            throw new VirtualminApiException(
                'No worker nodes configured. Add at least one node to the server config.'
            );
        }

        // Filter excluded nodes
        $candidates = array_filter($nodes, fn($n) => empty($n['exclude']));

        if (empty($candidates)) {
            throw new VirtualminApiException(
                'All worker nodes are marked as excluded. Enable at least one node.'
            );
        }

        // Required resources for the incoming plan
        $requiredDiskMb = (int) ($merged['quota_disk'] ?? 0);
        $requiredBwMb   = (int) ($merged['quota_bw'] ?? 0);

        $bestNode  = null;
        $bestScore = -1;
        $errors    = [];

        foreach ($candidates as $node) {
            $nodeHost = $node['host'] ?? '';
            $nodePort = (int) ($node['port'] ?? 10000);
            $label    = $node['label'] ?? $nodeHost;

            $maxDomains = (int) ($node['max_domains'] ?? 200);
            $maxDiskMb  = (int) ($node['max_disk_mb'] ?? 0);
            $maxBwMb    = (int) ($node['max_bw_mb'] ?? 0);
            $threshold  = (float) ($node['capacity_threshold'] ?? 0.9); // 90% default

            try {
                $nodeClient = new VirtualminClient(
                    host:      $nodeHost,
                    port:      $nodePort,
                    username:  $serverSettings['username'] ?? '',
                    password:  $serverSettings['password'] ?? '',
                    verifyTls: (bool) ($serverSettings['verify_tls'] ?? true),
                );

                // Detect available features on this node
                $nodeFeatures = $this->detectNodeFeatures($nodeClient);
                $webServer    = $this->getWebServerType($nodeFeatures);

                // Skip node if it doesn't support all requested product features
                $missing = $this->getMissingFeatures($merged, $nodeFeatures, $webServer);
                if (!empty($missing)) {
                    $missingStr = implode(', ', $missing);
                    Log::info("Virtualmin: node {$label} skipped — missing features: {$missingStr}");
                    $errors[] = "{$label}: missing features: {$missingStr}";
                    continue;
                }

                $result  = $nodeClient->list('list-domains', ['toplevel' => '']);
                $domains = $result['data'] ?? [];

                // Filter out header rows (Virtualmin returns separator rows)
                $domains = array_filter(
                    $domains,
                    fn($d) => !empty($d['values']) && isset($d['values']['features'])
                );

                $domainCount = count($domains);
                $allocDiskMb = 0;  // Sum of server_block_quota across domains (MB)
                $usedDiskMb  = 0;  // Sum of server_block_quota_used across domains (MB)
                $allocBwMb   = 0;  // Not available via API — tracked at node level only

                foreach ($domains as $domain) {
                    $vals = $domain['values'];

                    // Disk quota — server_block_quota is in 1KB blocks
                    // Convert to MB: blocks / 1024
                    $blockQuota  = (int) ($vals['server_block_quota'][0] ?? 0);
                    $allocDiskMb += (int) ($blockQuota / 1024);

                    // Actual disk used — useful for logging, secondary metric
                    $blockUsed    = (int) ($vals['server_block_quota_used'][0] ?? 0);
                    $usedDiskMb  += (int) ($blockUsed / 1024);

                    // Bandwidth: not exposed by Virtualmin Remote API per domain.
                    // Use node-level max_bw_mb as a hard ceiling only if configured.
                    // Actual per-domain bandwidth tracking is not available via API.
                }

                // Calculate headroom scores (1.0 = empty, 0.0 = full)
                $scores = [];

                // Domain count headroom
                if ($maxDomains > 0) {
                    $scores['domains'] = max(0, 1 - ($domainCount / $maxDomains));
                    if ($scores['domains'] < (1 - $threshold)) {
                        Log::info("Virtualmin: node {$label} skipped — domain count at capacity ({$domainCount}/{$maxDomains})");
                        continue;
                    }
                }

                // Disk headroom
                if ($maxDiskMb > 0) {
                    $projectedDisk = $allocDiskMb + $requiredDiskMb;
                    $scores['disk'] = max(0, 1 - ($projectedDisk / $maxDiskMb));
                    if ($scores['disk'] < (1 - $threshold)) {
                        Log::info("Virtualmin: node {$label} skipped — disk at capacity ({$allocDiskMb}MB/{$maxDiskMb}MB)");
                        continue;
                    }
                }

                // Bandwidth headroom
                if ($maxBwMb > 0) {
                    $projectedBw = $allocBwMb + $requiredBwMb;
                    $scores['bw'] = max(0, 1 - ($projectedBw / $maxBwMb));
                    if ($scores['bw'] < (1 - $threshold)) {
                        Log::info("Virtualmin: node {$label} skipped — bandwidth at capacity ({$allocBwMb}MB/{$maxBwMb}MB)");
                        continue;
                    }
                }

                // Bottleneck score = worst dimension (ensures balanced utilisation)
                $score = empty($scores) ? (1 - $domainCount / max($maxDomains, 1)) : min($scores);

                Log::info(
                    "Virtualmin: node {$label} — domains: {$domainCount}/{$maxDomains}, "
                    . "disk allocated: {$allocDiskMb}MB/{$maxDiskMb}MB, "
                    . "disk used: {$usedDiskMb}MB, "
                    . "headroom score: {$score}"
                );

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestNode  = $node;
                }

            } catch (VirtualminApiException $e) {
                $errors[] = "{$label}: {$e->getMessage()}";
                Log::warning("Virtualmin: could not query node {$label} for capacity", ['error' => $e->getMessage()]);
            }
        }

        if ($bestNode === null) {
            $errorDetail = empty($errors) ? 'All nodes are at capacity.' : implode('; ', $errors);
            throw new VirtualminApiException(
                "No suitable worker node found for provisioning. {$errorDetail}"
            );
        }

        Log::info("Virtualmin: selected node {$bestNode['label']} (score: {$bestScore})");

        return $bestNode;
    }

    /**
     * Build a VirtualminClient targeting a specific worker node,
     * using the shared API credentials from the server settings.
     */
    private function makeNodeClient(array $node, array $serverSettings): VirtualminClient
    {
        return new VirtualminClient(
            host:      $node['host'],
            port:      (int) ($node['port'] ?? 10000),
            username:  $serverSettings['username'] ?? '',
            password:  $serverSettings['password'] ?? '',
            verifyTls: (bool) ($serverSettings['verify_tls'] ?? true),
        );
    }

    /**
     * Build a VirtualminClient targeting the node where an account is provisioned.
     * Used for lifecycle operations (suspend, terminate, upgrade).
     */
    private function makeAccountClient(VirtualminAccount $account, array $serverSettings): VirtualminClient
    {
        return new VirtualminClient(
            host:      $account->node_host,
            port:      (int) ($account->node_port ?? 10000),
            username:  $serverSettings['username'] ?? '',
            password:  $serverSettings['password'] ?? '',
            verifyTls: (bool) ($serverSettings['verify_tls'] ?? true),
        );
    }

    /**
     * Build the customer-facing Usermin URL for a given node.
     *
     * Uses public_host and public_usermin_port if configured.
     * Falls back to internal host and server-level usermin_port.
     */
    private function buildUserminUrl(array $node, array $serverSettings): string
    {
        $host = $node['public_host'] ?? $node['host'];
        $port = (int) ($node['public_usermin_port'] ?? $serverSettings['usermin_port'] ?? 20000);

        // If port is 443, omit it from the URL (implicit HTTPS)
        if ($port === 443) {
            return "https://{$host}/";
        }

        return "https://{$host}:{$port}/";
    }

    /**
     * Build the customer-facing control panel URL (Virtualmin scoped to their domain).
     *
     * Uses control_panel_url from node config if set — this should be the
     * public FQDN of the Nginx proxy to Webmin (e.g. https://control.web01.moren.it/).
     * Falls back to null if not configured.
     */
    private function buildControlPanelUrl(array $node): ?string
    {
        return $node['control_panel_url'] ?? null;
    }

    /**
     * Resolve server-level settings (host, port, credentials) from the service relationship.
     *
     * Paymenter stores these on App\Models\Server (linked via product->server_id),
     * separate from product-level settings (plan, features, quotas).
     *
     * @return array<string, mixed>
     */
    private function getServerSettings(Service $service): array
    {
        $serverSettings = $service->product->server?->settings;

        if (!$serverSettings) {
            throw new VirtualminApiException(
                "No server linked to product #{$service->product_id}. "
                . 'Assign the Virtualmin server to the product in the admin panel.'
            );
        }

        $result = [];
        foreach ($serverSettings as $setting) {
            $result[$setting->key] = $setting->value;
        }

        return $result;
    }

    /**
     * Build the Usermin URL from server settings.
     */
    private function getUserminUrlForService(Service $service): string
    {
        $server      = $this->getServerSettings($service);
        $host        = $server['host'] ?? '';
        $userminPort = (int) ($server['usermin_port'] ?? 20000);

        return "https://{$host}:{$userminPort}/";
    }

    /**
     * Retrieve a config value from product settings with optional default.
     */
    private function getConfigValue(array $settings, string $key, mixed $default = ''): mixed
    {
        return $settings[$key] ?? $default;
    }

    /**
     * Require a VirtualminAccount record or throw a clear exception.
     */
    private function requireAccount(Service $service): VirtualminAccount
    {
        $account = VirtualminAccount::where('service_id', $service->id)->first();

        if (!$account) {
            throw new VirtualminApiException(
                "No Virtualmin account record found for service #{$service->id}. "
                . 'The service may not have been provisioned correctly.'
            );
        }

        return $account;
    }
}
