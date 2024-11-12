<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? env("APP_NAME") }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-100 font-sans leading-normal tracking-normal">

    <!-- Header -->
    <header class="header">
        <div class="container-standard flex justify-between items-center">
            <a href="{{ url('/') }}" class="header-logo">
                {{ env("APP_NAME") }}
            </a>
            <!-- <nav class="space-x-4">
                <a href="{{ url('/file-uploader') }}" class="nav-link">File Uploader</a>
                <a href="{{ url('/url-shortener') }}" class="nav-link">URL Shortener</a>
                <a href="{{ url('/paste-bin') }}" class="nav-link">Paste Bin</a>
            </nav> -->
            <div class="flex justify-center items-center gap-4">
                @auth
                    <a href="{{ url('/dashboard') }}" class="auth-button">Dashboard</a>
                    
                    <!-- <a href="{{ route('logout') }}" class="logout-button">Logout</a> -->
                    <div
                        class="
                            user-profile-menu-button
                            w-10
                            h-10
                            rounded-xl
                            overflow-hidden
                            cursor-pointer
                            border
                            border-gray-200
                        "
                        onclick="openUserMenu(event)"
                    >
                        <img
                            src="{{ Auth::user()->image }}"
                            class="object-cover w-full h-full pointer-events-none"
                        >
                    </div>

                    <script>
                        /**
                         * @param element {HTMLElement}
                         * @param parent {HTMLElement}
                         */
                        function isParentTo(element, parent) {
                            let i = 0;
                            let toCheck = element;
                            while (toCheck) {
                                console.log(toCheck, parent, toCheck === parent);
                                if (toCheck === parent) return true;
                                i++;

                                toCheck = toCheck.parentElement;

                                if (i > 100) { // Failsafe
                                    return false;
                                }
                            }

                            return false;
                        }
                        
                        /** @param event {MouseEvent} */
                        function openUserMenu(event) {
                            /** @type {HTMLElement} */
                            const element = event.currentTarget;
                            console.log("Open", element);

                            const div = document.createElement("div");
                            
                            const list = [
                                {
                                    label: "Profile",
                                    url: "/profile"
                                },
                                {
                                    label: "Logout",
                                    url: "{{ route('logout') }}"
                                }
                            ];

                            div.classList.add("user-profile-menu");

                            
                            for (let i = 0; i < list.length; i++) {
                                const item = list[i];
                                const a = document.createElement("a");
                                a.classList.add("user-profile-menu--item");
                                a.textContent = item.label;
                                a.href = item.url;

                                div.appendChild(a);
                            }

                            const box = element.getBoundingClientRect();
                            
                            div.style.left = `${box.left - box.width}px`;
                            div.style.top = `${box.bottom}px`;

                            /** @param e {MouseEvent} */
                            const handler = e => {
                                console.log(e.target);
                                
                                if (isParentTo(e.target, div)) {
                                    return;
                                }
                                
                                div.remove();
                                window.removeEventListener("click", handler);
                                window.removeEventListener("resize", onResize);
                            };

                            /** @param e {MouseEvent} */
                            const onResize = e => {
                                const box = element.getBoundingClientRect();
                                div.style.left = `${box.left - box.width}px`;
                                div.style.top = `${box.bottom}px`;
                            };
                            
                            document.body.appendChild(div);
                            setTimeout(() => {
                                window.addEventListener("click", handler);
                                window.addEventListener("resize", onResize);
                            }, 0);
                        }

                        function closeUserMenu() {
                            console.log("Close");
                        }
                    </script>
                @else
                    <a href="{{ route('login') }}" class="auth-button">Login</a>
                @endauth
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="container-standard main-content">
        @yield('content')
    </main>

    <!-- Footer -->
    <footer class="footer">
        Powered by {{ env("APP_NAME") }}
    </footer>
</body>
</html>
