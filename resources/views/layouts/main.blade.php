<!DOCTYPE html>
<html lang="en">
@php
    $appTitle = App\Models\Configuration::appTitle();
@endphp
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? $appTitle }}</title>
    @include('partials.app-icons')
    @vite(['resources/css/app.css', 'resources/js/app.ts'])
    @auth
        @php
            $accentThemeVariables = auth()->user()->accentThemeVariables();
        @endphp
        @if ($accentThemeVariables)
            <style>
                :root {
                    @foreach ($accentThemeVariables as $accentThemeVariable => $accentThemeValue)
                        {{ $accentThemeVariable }}: {{ $accentThemeValue }};
                    @endforeach
                }
            </style>
        @endif
    @endauth
</head>
<body>

    <header class="header">
        <div class="container-standard flex justify-between items-center">
            <a href="{{ url('/') }}" class="header-logo">
                @include('partials.app-mark', ['class' => 'header-logo-mark', 'alt' => ''])
                <span class="header-logo-text">{{ $appTitle }}</span>
            </a>
            <div class="nav-actions">
                @auth
                    @php
                        $user = auth()->user();
                        $avatarInitial = strtoupper(substr($user->name ?: $user->email ?: env("APP_NAME"), 0, 1));
                    @endphp
                    @if ($user->isAdmin())
                        <a href="{{ url('/admin/users') }}" class="auth-button">Admin</a>
                    @endif
                    <a href="{{ route('files') }}" class="auth-button nav-optional">Files</a>
                    <a href="{{ route('directories') }}" class="auth-button nav-optional">Directories</a>
                    <a href="{{ route('uploadlinks') }}" class="auth-button nav-optional">Upload Links</a>
                    <a href="{{ url('/dashboard') }}" class="auth-button">Dashboard</a>
                    <button
                        type="button"
                        class="user-profile-menu-button h-10 w-10 overflow-hidden rounded-lg border"
                        aria-label="Open profile menu"
                        onclick="openUserMenu(event)"
                    >
                        <span>{{ $avatarInitial }}</span>
                        @if ($user->image)
                            <img
                                src="{{ $user->image }}"
                                class="object-cover w-full h-full pointer-events-none"
                                onerror="this.remove()"
                            >
                        @endif
                    </button>

                    <script>
                        function positionUserMenu(menu, anchor) {
                            const anchorBox = anchor.getBoundingClientRect();
                            const menuBox = menu.getBoundingClientRect();
                            const left = Math.min(
                                window.innerWidth - menuBox.width - 12,
                                Math.max(12, anchorBox.right - menuBox.width)
                            );

                            menu.style.left = `${left}px`;
                            menu.style.top = `${anchorBox.bottom + 8}px`;
                        }
                        
                        function openUserMenu(event) {
                            event.stopPropagation();
                            const element = event.currentTarget;
                            document.querySelectorAll(".user-profile-menu").forEach(menu => menu.remove());

                            const div = document.createElement("div");
                            const list = [
                                {
                                    label: "{{ $user->getRoleName() }}",
                                },
                                {
                                    label: "Profile",
                                    url: "{{ route('profile') }}"
                                },
                                {
                                    label: "Logout",
                                    url: "{{ route('logout') }}"
                                }
                            ];

                            div.classList.add("user-profile-menu");

                            for (let i = 0; i < list.length; i++) {
                                const item = list[i];
                                const a = document.createElement(item.url ? "a" : "div");
                                a.classList.add("user-profile-menu--item");
                                a.textContent = item.label;
                                if (item.url) {
                                    a.href = item.url;
                                }

                                div.appendChild(a);
                            }

                            const close = () => {
                                div.remove();
                                window.removeEventListener("click", onClick);
                                window.removeEventListener("resize", onResize);
                                window.removeEventListener("keydown", onKeydown);
                            };

                            const onClick = e => {
                                if (div.contains(e.target) || element.contains(e.target)) {
                                    return;
                                }
                                
                                close();
                            };

                            const onResize = () => positionUserMenu(div, element);
                            const onKeydown = e => {
                                if (e.key === "Escape") {
                                    close();
                                }
                            };
                            
                            document.body.appendChild(div);
                            positionUserMenu(div, element);

                            setTimeout(() => {
                                window.addEventListener("click", onClick);
                                window.addEventListener("resize", onResize);
                                window.addEventListener("keydown", onKeydown);
                            }, 0);
                        }
                    </script>
                @else
                    <a href="{{ route('login') }}" class="auth-button">Login</a>
                @endauth
            </div>
        </div>
    </header>

    <main class="container-standard main-content">
        @yield('content')
    </main>

    <footer class="footer">
        Powered by Shup
    </footer>
</body>
</html>
