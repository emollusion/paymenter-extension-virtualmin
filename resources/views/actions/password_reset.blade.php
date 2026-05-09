{{--
    Virtualmin extension – Password Reset result view.
    Displays the newly generated password ONCE.
    The password is NOT stored anywhere — this is the only opportunity to view it.
    Future: replace with SSO token flow once Usermin session API is integrated.
--}}
<div class="space-y-4">
    <div class="rounded-lg border border-amber-300 bg-amber-50 p-5 shadow-sm dark:border-amber-700 dark:bg-amber-900/20">
        <div class="flex items-start gap-3">
            <svg xmlns="http://www.w3.org/2000/svg" class="mt-0.5 h-5 w-5 flex-shrink-0 text-amber-600 dark:text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
            </svg>
            <div>
                <h3 class="text-sm font-semibold text-amber-800 dark:text-amber-200">
                    Save this password — it will not be shown again
                </h3>
                <p class="mt-1 text-xs text-amber-700 dark:text-amber-300">
                    This password is displayed once and is not stored. Copy it now and change it in Usermin after logging in.
                </p>
            </div>
        </div>
    </div>

    <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <h3 class="mb-4 text-base font-semibold text-gray-900 dark:text-white">
            New Credentials
        </h3>

        <dl class="space-y-3">
            <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    Domain
                </dt>
                <dd class="mt-1 font-mono text-sm text-gray-900 dark:text-white">
                    {{ $account->domain }}
                </dd>
            </div>

            <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    Username
                </dt>
                <dd class="mt-1 font-mono text-sm text-gray-900 dark:text-white">
                    {{ $account->username }}
                </dd>
            </div>

            <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    New Password
                </dt>
                <dd class="mt-1 flex items-center gap-3">
                    <code class="rounded bg-gray-100 px-3 py-1.5 font-mono text-sm font-bold text-gray-900 dark:bg-gray-700 dark:text-white select-all">
                        {{ $newPassword }}
                    </code>
                    <button
                        type="button"
                        onclick="navigator.clipboard.writeText('{{ $newPassword }}').then(() => this.textContent = 'Copied!')"
                        class="text-xs text-primary-600 hover:text-primary-700 dark:text-primary-400"
                    >
                        Copy
                    </button>
                </dd>
            </div>
        </dl>

        @if($account->usermin_url)
        <div class="mt-5">
            <a
                href="{{ $account->usermin_url }}"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex items-center gap-2 rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                </svg>
                Log in to Usermin now
            </a>
        </div>
        @endif
    </div>
</div>
