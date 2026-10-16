<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Capeonn — Work without boundaries | Download for Windows & Android</title>
    <meta name="description" content="Download Capeonn for Windows (.exe) and Android (.apk). The ultimate productivity and project management companion app.">
    
    <!-- Modern Typography: Plus Jakarta Sans -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --font-main: 'Plus Jakarta Sans', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            --color-bg: #f8fafc;
            --color-surface: #ffffff;
            --color-text-primary: #0f172a;
            --color-text-secondary: #475569;
            --color-text-muted: #94a3b8;
            --color-border: #e2e8f0;
            
            /* Brand & Accent Colors */
            --win-blue-1: #0284c7;
            --win-blue-2: #0369a1;
            --win-blue-glow: rgba(2, 132, 199, 0.35);
            
            --droid-green-1: #16a34a;
            --droid-green-2: #15803d;
            --droid-green-glow: rgba(22, 163, 74, 0.35);

            --radius-sm: 8px;
            --radius-md: 14px;
            --radius-lg: 20px;
            --radius-xl: 28px;
            --radius-full: 9999px;
            
            --shadow-subtle: 0 4px 20px -2px rgba(15, 23, 42, 0.05);
            --shadow-card: 0 10px 30px -4px rgba(15, 23, 42, 0.08);
            --shadow-float: 0 20px 40px -10px rgba(15, 23, 42, 0.12);
        }

        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html {
            scroll-behavior: smooth;
            -webkit-font-smoothing: antialiased;
            text-rendering: optimizeLegibility;
        }

        body {
            font-family: var(--font-main);
            background-color: var(--color-bg);
            color: var(--color-text-primary);
            line-height: 1.5;
            min-height: 100vh;
            overflow-x: hidden;
            position: relative;
        }

        /* Ambient Glow Background matching the reference photo */
        .ambient-bg {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 900px;
            pointer-events: none;
            z-index: 0;
            overflow: hidden;
            background: 
                radial-gradient(circle at 12% 15%, rgba(254, 243, 199, 0.6) 0%, rgba(254, 243, 199, 0) 45%),
                radial-gradient(circle at 88% 18%, rgba(224, 242, 254, 0.7) 0%, rgba(224, 242, 254, 0) 50%),
                radial-gradient(circle at 50% 30%, rgba(241, 245, 249, 0.4) 0%, rgba(241, 245, 249, 0) 70%);
        }

        .container {
            max-width: 1240px;
            margin: 0 auto;
            padding: 0 24px;
            position: relative;
            z-index: 1;
        }

        /* Header / Navbar */
        .navbar-wrapper {
            position: sticky;
            top: 0;
            z-index: 50;
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            background: rgba(255, 255, 255, 0.85);
            border-bottom: 1px solid rgba(226, 232, 240, 0.7);
            transition: all 0.2s ease;
        }

        .navbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            height: 80px;
        }

        .brand-logo {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: var(--color-text-primary);
        }

        .logo-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #ec4899 0%, #8b5cf6 50%, #3b82f6 100%);
            box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3);
            position: relative;
        }

        .logo-icon svg {
            width: 22px;
            height: 22px;
            color: #ffffff;
        }

        .brand-name {
            font-size: 1.35rem;
            font-weight: 800;
            letter-spacing: -0.02em;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .brand-pill {
            font-size: 0.72rem;
            font-weight: 700;
            color: #2563eb;
            background: #eff6ff;
            border: 1px solid #dbeafe;
            padding: 2px 8px;
            border-radius: var(--radius-full);
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .nav-links {
            display: flex;
            align-items: center;
            gap: 32px;
            list-style: none;
        }

        .nav-link {
            text-decoration: none;
            color: var(--color-text-secondary);
            font-size: 0.95rem;
            font-weight: 600;
            transition: color 0.15s ease;
        }

        .nav-link:hover {
            color: var(--color-text-primary);
        }

        .nav-actions {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .btn-outline {
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 9px 22px;
            font-size: 0.92rem;
            font-weight: 600;
            color: #0f172a;
            background: #ffffff;
            border: 1.5px solid #0f172a;
            border-radius: var(--radius-full);
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .btn-outline:hover {
            background: #0f172a;
            color: #ffffff;
            box-shadow: 0 4px 14px rgba(15, 23, 42, 0.2);
            transform: translateY(-1px);
        }

        /* Hero Section */
        .hero-section {
            padding: 56px 0 72px 0;
        }

        .hero-grid {
            display: grid;
            grid-template-columns: 1.05fr 0.95fr;
            gap: 48px;
            align-items: center;
        }

        .hero-left {
            max-width: 580px;
        }

        .hero-title {
            font-size: clamp(2.75rem, 5vw, 4rem);
            font-weight: 800;
            line-height: 1.12;
            letter-spacing: -0.035em;
            color: #0f172a;
            margin-bottom: 20px;
        }

        .hero-subtitle {
            font-size: 1.125rem;
            line-height: 1.65;
            color: var(--color-text-secondary);
            margin-bottom: 36px;
            max-width: 490px;
        }

        /* Download Action Cards matching sample */
        .download-cards-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
            margin-bottom: 24px;
        }

        .download-card {
            display: flex;
            flex-direction: column;
            padding: 24px 22px;
            border-radius: var(--radius-lg);
            text-decoration: none;
            color: #ffffff;
            position: relative;
            overflow: hidden;
            cursor: pointer;
            box-shadow: 0 8px 24px -4px rgba(15, 23, 42, 0.12);
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            border: 1px solid rgba(255, 255, 255, 0.18);
        }

        .download-card::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 1px;
            background: linear-gradient(90deg, rgba(255,255,255,0) 0%, rgba(255,255,255,0.4) 50%, rgba(255,255,255,0) 100%);
        }

        .download-card:hover {
            transform: translateY(-5px);
        }

        .download-card:active {
            transform: translateY(-2px) scale(0.99);
        }

        /* Windows Card */
        .download-card.windows {
            background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);
        }

        .download-card.windows:hover {
            box-shadow: 0 16px 36px -6px var(--win-blue-glow);
        }

        /* Android Card */
        .download-card.android {
            background: linear-gradient(135deg, #16a34a 0%, #15803d 100%);
        }

        .download-card.android:hover {
            box-shadow: 0 16px 36px -6px var(--droid-green-glow);
        }

        .card-icon-wrap {
            margin-bottom: 22px;
            width: 44px;
            height: 44px;
            display: flex;
            align-items: center;
            justify-content: flex-start;
        }

        .card-icon-wrap svg {
            width: 36px;
            height: 36px;
            fill: #ffffff;
        }

        .card-main-title {
            font-size: 1.15rem;
            font-weight: 700;
            line-height: 1.25;
            margin-bottom: 4px;
            letter-spacing: -0.01em;
        }

        .card-subtext {
            font-size: 0.88rem;
            font-weight: 500;
            color: rgba(255, 255, 255, 0.88);
            margin-bottom: 8px;
        }

        .card-meta {
            font-size: 0.82rem;
            font-weight: 600;
            color: rgba(255, 255, 255, 0.75);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .card-meta .dot {
            font-size: 0.75rem;
            opacity: 0.6;
        }

        /* Hero Right: Device Showcase Mockup */
        .hero-right {
            position: relative;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .device-mockup-container {
            position: relative;
            width: 100%;
            max-width: 580px;
            border-radius: var(--radius-xl);
            transition: transform 0.3s ease;
        }

        .device-mockup-img {
            width: 100%;
            height: auto;
            display: block;
            border-radius: var(--radius-lg);
            filter: drop-shadow(0 25px 35px rgba(15, 23, 42, 0.15));
            transition: transform 0.4s ease, filter 0.4s ease;
        }

        .device-mockup-container:hover .device-mockup-img {
            transform: scale(1.02);
            filter: drop-shadow(0 30px 45px rgba(15, 23, 42, 0.2));
        }

        /* Folder Indicator Info Banner */
        .folder-info-bar {
            background: #ffffff;
            border: 1px solid var(--color-border);
            border-radius: var(--radius-md);
            padding: 12px 18px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            font-size: 0.85rem;
            color: var(--color-text-secondary);
            box-shadow: var(--shadow-subtle);
        }

        .folder-info-left {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .folder-badge {
            background: #f1f5f9;
            color: #334155;
            font-family: ui-monospace, monospace;
            font-size: 0.8rem;
            font-weight: 600;
            padding: 3px 8px;
            border-radius: 6px;
        }

        .folder-copy-btn {
            background: transparent;
            border: 1px solid var(--color-border);
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.78rem;
            font-weight: 600;
            color: #475569;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.15s ease;
        }

        .folder-copy-btn:hover {
            background: #f8fafc;
            color: #0f172a;
            border-color: #cbd5e1;
        }

        /* "Why Capeonn?" Section matching reference */
        .why-section {
            padding: 80px 0;
            border-top: 1px solid #f1f5f9;
        }

        .section-header {
            text-align: center;
            max-width: 650px;
            margin: 0 auto 52px auto;
        }

        .section-tag {
            display: inline-block;
            font-size: 0.8rem;
            font-weight: 700;
            color: #2563eb;
            background: #eff6ff;
            border: 1px solid #dbeafe;
            padding: 4px 14px;
            border-radius: var(--radius-full);
            margin-bottom: 12px;
            letter-spacing: 0.02em;
        }

        .section-title {
            font-size: 2.25rem;
            font-weight: 800;
            letter-spacing: -0.025em;
            color: #0f172a;
            margin-bottom: 12px;
        }

        .section-subtitle {
            font-size: 1.05rem;
            color: var(--color-text-secondary);
            line-height: 1.6;
        }

        .feature-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 24px;
        }

        .feature-card {
            background: #ffffff;
            border: 1px solid var(--color-border);
            border-radius: var(--radius-lg);
            padding: 30px;
            transition: all 0.25s ease;
            box-shadow: var(--shadow-subtle);
            position: relative;
        }

        .feature-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-card);
            border-color: #cbd5e1;
        }

        .feature-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 20px;
        }

        .feature-icon.icon-blue {
            background: #eff6ff;
            color: #2563eb;
        }

        .feature-icon.icon-green {
            background: #f0fdf4;
            color: #16a34a;
        }

        .feature-icon.icon-purple {
            background: #faf5ff;
            color: #9333ea;
        }

        .feature-icon.icon-orange {
            background: #fff7ed;
            color: #ea580c;
        }

        .feature-icon.icon-cyan {
            background: #ecfeff;
            color: #0891b2;
        }

        .feature-icon.icon-rose {
            background: #fff1f2;
            color: #e11d48;
        }

        .feature-icon svg {
            width: 26px;
            height: 26px;
        }

        .feature-title {
            font-size: 1.2rem;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 10px;
            letter-spacing: -0.01em;
        }

        .feature-desc {
            font-size: 0.95rem;
            color: var(--color-text-secondary);
            line-height: 1.6;
        }

        /* Specs Section */
        .specs-section {
            padding: 80px 0;
            background: #ffffff;
            border-top: 1px solid #f1f5f9;
            border-bottom: 1px solid #f1f5f9;
        }

        .specs-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 32px;
            max-width: 960px;
            margin: 0 auto;
        }

        .specs-card {
            border: 1px solid var(--color-border);
            border-radius: var(--radius-lg);
            padding: 32px;
            background: var(--color-bg);
        }

        .specs-header {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 24px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--color-border);
        }

        .specs-header-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .specs-header-icon.win {
            background: #0284c7;
            color: #ffffff;
        }

        .specs-header-icon.droid {
            background: #16a34a;
            color: #ffffff;
        }

        .specs-header-icon svg {
            width: 24px;
            height: 24px;
        }

        .specs-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: #0f172a;
        }

        .specs-list {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .specs-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.92rem;
        }

        .specs-label {
            color: var(--color-text-secondary);
            font-weight: 500;
        }

        .specs-val {
            color: var(--color-text-primary);
            font-weight: 600;
            font-family: ui-monospace, monospace;
            background: #ffffff;
            padding: 2px 8px;
            border-radius: 4px;
            border: 1px solid var(--color-border);
        }

        /* FAQ Section */
        .faq-section {
            padding: 80px 0;
        }

        .faq-grid {
            max-width: 820px;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .faq-item {
            background: #ffffff;
            border: 1px solid var(--color-border);
            border-radius: var(--radius-md);
            overflow: hidden;
            transition: all 0.2s ease;
        }

        .faq-item:hover {
            border-color: #cbd5e1;
        }

        .faq-question {
            padding: 20px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            font-size: 1.05rem;
            font-weight: 700;
            color: #0f172a;
            user-select: none;
        }

        .faq-arrow {
            width: 20px;
            height: 20px;
            transition: transform 0.2s ease;
            color: #94a3b8;
        }

        .faq-item.active .faq-arrow {
            transform: rotate(180deg);
            color: #2563eb;
        }

        .faq-answer {
            padding: 0 24px 20px 24px;
            font-size: 0.95rem;
            line-height: 1.6;
            color: var(--color-text-secondary);
            display: none;
        }

        .faq-item.active .faq-answer {
            display: block;
        }

        /* Footer */
        .footer {
            background: #ffffff;
            border-top: 1px solid var(--color-border);
            padding: 48px 0;
            margin-top: 40px;
        }

        .footer-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 0.9rem;
            color: var(--color-text-muted);
        }

        .footer-links {
            display: flex;
            align-items: center;
            gap: 24px;
        }

        .footer-links a {
            color: var(--color-text-secondary);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.15s ease;
        }

        .footer-links a:hover {
            color: var(--color-text-primary);
        }

        /* Toast notification */
        .toast {
            position: fixed;
            bottom: 30px;
            right: 30px;
            background: #0f172a;
            color: #ffffff;
            padding: 16px 22px;
            border-radius: var(--radius-md);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.25);
            display: flex;
            align-items: center;
            gap: 14px;
            z-index: 1000;
            transform: translateY(120px);
            opacity: 0;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            font-size: 0.95rem;
            font-weight: 500;
        }

        .toast.show {
            transform: translateY(0);
            opacity: 1;
        }

        .toast-icon {
            width: 22px;
            height: 22px;
            color: #10b981;
        }

        /* Responsive Breakpoints */
        @media (max-width: 992px) {
            .hero-grid {
                grid-template-columns: 1fr;
                gap: 48px;
                text-align: center;
            }

            .hero-left {
                max-width: 100%;
                margin: 0 auto;
            }

            .hero-subtitle {
                margin-left: auto;
                margin-right: auto;
            }

            .download-cards-row {
                max-width: 480px;
                margin-left: auto;
                margin-right: auto;
            }

            .folder-info-bar {
                max-width: 480px;
                margin: 0 auto;
            }

            .feature-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .specs-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 640px) {
            .navbar {
                height: 70px;
            }

            .nav-links {
                display: none;
            }

            .download-cards-row {
                grid-template-columns: 1fr;
            }

            .feature-grid {
                grid-template-columns: 1fr;
            }

            .footer-content {
                flex-direction: column;
                gap: 18px;
                text-align: center;
            }
        }
    </style>
</head>
<body>

    <!-- Ambient Gradient Glow -->
    <div class="ambient-bg"></div>

    <!-- Navigation Bar -->
    <header class="navbar-wrapper">
        <div class="container">
            <nav class="navbar">
                <a href="{{ route('home') }}" class="brand-logo" id="brand-logo-link">
                    <div class="logo-icon">
                        <!-- Play / folded geometric logo icon matching modern aesthetic -->
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <polygon points="5 3 19 12 5 21 5 3" fill="currentColor" fill-opacity="0.9"></polygon>
                        </svg>
                    </div>
                    <span class="brand-name">
                        Capeonn
                        <span class="brand-pill">v1.0.4</span>
                    </span>
                </a>

                <ul class="nav-links">
                    <li><a href="#features" class="nav-link">Features</a></li>
                    <li><a href="#benefits" class="nav-link">Benefits</a></li>
                    <li><a href="#specs" class="nav-link">Specs</a></li>
                    <li><a href="#faq" class="nav-link">FAQ</a></li>
                </ul>

                <div class="nav-actions">
                    <a href="#download-section" class="btn-outline" id="nav-get-started-btn">Get Started</a>
                </div>
            </nav>
        </div>
    </header>

    <!-- Main Content -->
    <main>
        <!-- Hero Section -->
        <section class="hero-section" id="download-section">
            <div class="container">
                <div class="hero-grid">
                    
                    <!-- Hero Left: Typography & Download Cards -->
                    <div class="hero-left">
                        <h1 class="hero-title">
                            Work without<br>
                            boundaries.
                        </h1>

                        <p class="hero-subtitle">
                            The ultimate productivity hub, now available on all your devices.
                            Get the companion app for Windows and Android.
                        </p>

                        <!-- Two Download Cards matching sample exactly -->
                        <div class="download-cards-row">
                            
                            <!-- Windows Download Card -->
                            <a href="{{ $exeInfo['download_url'] }}" 
                               download="{{ $exeInfo['name'] }}"
                               class="download-card windows" 
                               id="btn-download-windows"
                               onclick="handleDownload('Windows', '{{ $exeInfo['name'] }}')">
                                <div class="card-icon-wrap">
                                    <!-- Official Windows 4-Square Logo -->
                                    <svg viewBox="0 0 88 88" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M0 12.402l35.689-4.86.016 34.423-35.67.203zm35.67 33.529l.026 34.453-35.67-4.875-.026-29.78zm4.326-39.043L87.95 0v41.524l-47.954.307zm47.986 47.781L40.022 88V46.608l47.984.341z"/>
                                    </svg>
                                </div>
                                <div class="card-main-title">Download for Windows</div>
                                <div class="card-subtext">{{ $exeInfo['platform'] }}</div>
                                <div class="card-meta">
                                    <span>{{ $exeInfo['version'] }}</span>
                                    <span class="dot">•</span>
                                    <span>{{ $exeInfo['size'] }}</span>
                                </div>
                            </a>

                            <!-- Android Download Card -->
                            <a href="{{ $apkInfo['download_url'] }}" 
                               download="{{ $apkInfo['name'] }}"
                               class="download-card android" 
                               id="btn-download-android"
                               onclick="handleDownload('Android', '{{ $apkInfo['name'] }}')">
                                <div class="card-icon-wrap">
                                    <!-- Official Android Robot Logo -->
                                    <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M17.523 15.3414c-.5511 0-.9993-.4486-.9993-1.0001s.4482-1.0001.9993-1.0001c.552 0 .9997.4486.9997 1.0001s-.4477 1.0001-.9997 1.0001m-11.046 0c-.5511 0-.9993-.4486-.9993-1.0001s.4482-1.0001.9993-1.0001c.552 0 .9997.4486.9997 1.0001s-.4477 1.0001-.9997 1.0001m11.4045-6.02l1.9973-3.4592a.416.416 0 0 0-.1521-.5676.416.416 0 0 0-.5676.1521l-2.0223 3.503C15.589 8.3582 13.8566 8 12 8s-3.589.3582-5.1368.9497L4.8409 5.4467a.4161.4161 0 0 0-.5677-.1521.4157.4157 0 0 0-.152 5676l1.9972 3.4592C2.6889 11.1867.3432 14.6589 0 18.761h24c-.3432-4.1021-2.6889-7.5743-6.1185-9.4396"/>
                                    </svg>
                                </div>
                                <div class="card-main-title">Download for Android</div>
                                <div class="card-subtext">{{ $apkInfo['platform'] }}</div>
                                <div class="card-meta">
                                    <span>{{ $apkInfo['version'] }}</span>
                                    <span class="dot">•</span>
                                    <span>{{ $apkInfo['size'] }}</span>
                                </div>
                            </a>

                        </div>

                        <!-- Downloads Folder Location Guide -->
                        <div class="folder-info-bar">
                            <div class="folder-info-left">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: #64748b;">
                                    <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path>
                                </svg>
                                <span>Serving from: <span class="folder-badge">public/downloads/</span></span>
                            </div>
                            <button class="folder-copy-btn" onclick="copyFolderPath()" id="copy-folder-btn" title="Copy absolute folder path">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                                    <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                                </svg>
                                <span id="copy-btn-text">Copy Path</span>
                            </button>
                        </div>

                    </div>

                    <!-- Hero Right: Photorealistic Device Showcase -->
                    <div class="hero-right">
                        <div class="device-mockup-container">
                            <img src="{{ asset('images/device_showcase.jpg') }}" 
                                 alt="Capeonn App on Laptop and Smartphone" 
                                 class="device-mockup-img"
                                 id="device-mockup-image">
                        </div>
                    </div>

                </div>
            </div>
        </section>

        <!-- "Why Capeonn?" Section matching reference bottom -->
        <section class="why-section" id="features">
            <div class="container">
                <div class="section-header">
                    <span class="section-tag">Enterprise Ready</span>
                    <h2 class="section-title">Why Capeonn?</h2>
                    <p class="section-subtitle">
                        Purpose-built for modern IT companies to manage teams, projects, and deadlines from any device with effortless real-time sync.
                    </p>
                </div>

                <div class="feature-grid">
                    
                    <!-- Card 1 -->
                    <div class="feature-card">
                        <div class="feature-icon icon-blue">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect>
                                <line x1="8" y1="21" x2="16" y2="21"></line>
                                <line x1="12" y1="17" x2="12" y2="21"></line>
                            </svg>
                        </div>
                        <h3 class="feature-title">Cross-Platform Freedom</h3>
                        <p class="feature-desc">
                            Work seamlessly across Windows desktop workstations and Android mobile devices with instant data parity.
                        </p>
                    </div>

                    <!-- Card 2 -->
                    <div class="feature-card">
                        <div class="feature-icon icon-green">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>
                            </svg>
                        </div>
                        <h3 class="feature-title">Real-Time Sync</h3>
                        <p class="feature-desc">
                            Tasks, comments, and milestones update instantly over WebSockets so your entire company stays aligned.
                        </p>
                    </div>

                    <!-- Card 3 -->
                    <div class="feature-card">
                        <div class="feature-icon icon-purple">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                                <circle cx="8.5" cy="7.5" r="4"></circle>
                                <polyline points="17 11 19 13 23 9"></polyline>
                            </svg>
                        </div>
                        <h3 class="feature-title">Hierarchy Management</h3>
                        <p class="feature-desc">
                            Structured flow from Manager to Team Lead to Team Member with role-based access and approval workflows.
                        </p>
                    </div>

                    <!-- Card 4 -->
                    <div class="feature-card">
                        <div class="feature-icon icon-orange">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"></circle>
                                <polyline points="12 6 12 12 16 14"></polyline>
                            </svg>
                        </div>
                        <h3 class="feature-title">Accurate Time Tracking</h3>
                        <p class="feature-desc">
                            Compare estimated vs. actual task hours to identify early, on-time, and late deliverables automatically.
                        </p>
                    </div>

                    <!-- Card 5 -->
                    <div class="feature-card">
                        <div class="feature-icon icon-cyan">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                            </svg>
                        </div>
                        <h3 class="feature-title">Team Chat & File Sharing</h3>
                        <p class="feature-desc">
                            Direct messaging and project channels with instant file attachments, document previews, and versioning.
                        </p>
                    </div>

                    <!-- Card 6 -->
                    <div class="feature-card">
                        <div class="feature-icon icon-rose">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="18" y1="20" x2="18" y2="10"></line>
                                <line x1="12" y1="20" x2="12" y2="4"></line>
                                <line x1="6" y1="20" x2="6" y2="14"></line>
                            </svg>
                        </div>
                        <h3 class="feature-title">Executive Analytics</h3>
                        <p class="feature-desc">
                            Actionable KPIs, sprint velocity charts, and department breakdown reports for managers and company leadership.
                        </p>
                    </div>

                </div>
            </div>
        </section>

        <!-- System Specifications Section ("Specs") -->
        <section class="specs-section" id="specs">
            <div class="container">
                <div class="section-header">
                    <span class="section-tag">Compatibility</span>
                    <h2 class="section-title">System Specifications</h2>
                    <p class="section-subtitle">
                        Lightweight and optimized for speed on modern hardware.
                    </p>
                </div>

                <div class="specs-grid">
                    <!-- Windows Specs -->
                    <div class="specs-card">
                        <div class="specs-header">
                            <div class="specs-header-icon win">
                                <svg viewBox="0 0 88 88" fill="currentColor">
                                    <path d="M0 12.402l35.689-4.86.016 34.423-35.67.203zm35.67 33.529l.026 34.453-35.67-4.875-.026-29.78zm4.326-39.043L87.95 0v41.524l-47.954.307zm47.986 47.781L40.022 88V46.608l47.984.341z"/>
                                </svg>
                            </div>
                            <div>
                                <h3 class="specs-title">Windows Companion</h3>
                                <p style="font-size: 0.85rem; color: #64748b;">Desktop Application</p>
                            </div>
                        </div>

                        <ul class="specs-list">
                            <li class="specs-item">
                                <span class="specs-label">Operating System</span>
                                <span class="specs-val">Windows 10 / 11 (64-bit)</span>
                            </li>
                            <li class="specs-item">
                                <span class="specs-label">Package Format</span>
                                <span class="specs-val">.exe (Standalone)</span>
                            </li>
                            <li class="specs-item">
                                <span class="specs-label">Package Size</span>
                                <span class="specs-val">{{ $exeInfo['size'] }}</span>
                            </li>
                            <li class="specs-item">
                                <span class="specs-label">Memory (RAM)</span>
                                <span class="specs-val">4 GB Min (8 GB Rec)</span>
                            </li>
                            <li class="specs-item">
                                <span class="specs-label">Local File</span>
                                <span class="specs-val">downloads/{{ $exeInfo['name'] }}</span>
                            </li>
                        </ul>
                    </div>

                    <!-- Android Specs -->
                    <div class="specs-card">
                        <div class="specs-header">
                            <div class="specs-header-icon droid">
                                <svg viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M17.523 15.3414c-.5511 0-.9993-.4486-.9993-1.0001s.4482-1.0001.9993-1.0001c.552 0 .9997.4486.9997 1.0001s-.4477 1.0001-.9997 1.0001m-11.046 0c-.5511 0-.9993-.4486-.9993-1.0001s.4482-1.0001.9993-1.0001c.552 0 .9997.4486.9997 1.0001s-.4477 1.0001-.9997 1.0001m11.4045-6.02l1.9973-3.4592a.416.416 0 0 0-.1521-.5676.416.416 0 0 0-.5676.1521l-2.0223 3.503C15.589 8.3582 13.8566 8 12 8s-3.589.3582-5.1368.9497L4.8409 5.4467a.4161.4161 0 0 0-.5677-.1521.4157.4157 0 0 0-.152 5676l1.9972 3.4592C2.6889 11.1867.3432 14.6589 0 18.761h24c-.3432-4.1021-2.6889-7.5743-6.1185-9.4396"/>
                                </svg>
                            </div>
                            <div>
                                <h3 class="specs-title">Android Companion</h3>
                                <p style="font-size: 0.85rem; color: #64748b;">Mobile Application</p>
                            </div>
                        </div>

                        <ul class="specs-list">
                            <li class="specs-item">
                                <span class="specs-label">Operating System</span>
                                <span class="specs-val">Android 8.0 (Oreo) or later</span>
                            </li>
                            <li class="specs-item">
                                <span class="specs-label">Package Format</span>
                                <span class="specs-val">.apk (Universal / arm64)</span>
                            </li>
                            <li class="specs-item">
                                <span class="specs-label">Package Size</span>
                                <span class="specs-val">{{ $apkInfo['size'] }}</span>
                            </li>
                            <li class="specs-item">
                                <span class="specs-label">Memory (RAM)</span>
                                <span class="specs-val">2 GB Min</span>
                            </li>
                            <li class="specs-item">
                                <span class="specs-label">Local File</span>
                                <span class="specs-val">downloads/{{ $apkInfo['name'] }}</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </section>

        <!-- Installation & FAQ Section ("FAQ") -->
        <section class="faq-section" id="faq">
            <div class="container">
                <div class="section-header">
                    <span class="section-tag">Quick Answers</span>
                    <h2 class="section-title">Frequently Asked Questions</h2>
                    <p class="section-subtitle">
                        Everything you need to know about setting up and running Capeonn on your devices.
                    </p>
                </div>

                <div class="faq-grid">
                    
                    <div class="faq-item active">
                        <div class="faq-question" onclick="toggleFaq(this)">
                            <span>Where do I put new .apk and .exe files?</span>
                            <svg class="faq-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polyline points="6 9 12 15 18 9"></polyline>
                            </svg>
                        </div>
                        <div class="faq-answer">
                            Place your compiled files directly into <code>public/downloads/</code> inside the Laravel backend folder (<code>capeonn-backend/public/downloads/</code>). The website automatically detects any <code>.apk</code> and <code>.exe</code> files placed there, reads their real file size, and provides the instant download links!
                        </div>
                    </div>

                    <div class="faq-item">
                        <div class="faq-question" onclick="toggleFaq(this)">
                            <span>How do I install the APK on an Android device?</span>
                            <svg class="faq-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polyline points="6 9 12 15 18 9"></polyline>
                            </svg>
                        </div>
                        <div class="faq-answer">
                            1. Tap the green <strong>Download for Android</strong> button from your phone browser.<br>
                            2. Open the downloaded <code>capeonn.apk</code> file.<br>
                            3. If your browser asks for permission, tap <em>Settings</em> and enable <em>"Allow from this source"</em>.<br>
                            4. Tap <strong>Install</strong> to complete installation and open the app.
                        </div>
                    </div>

                    <div class="faq-item">
                        <div class="faq-question" onclick="toggleFaq(this)">
                            <span>What if Windows Defender SmartScreen shows a warning?</span>
                            <svg class="faq-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polyline points="6 9 12 15 18 9"></polyline>
                            </svg>
                        </div>
                        <div class="faq-answer">
                            When running a newly compiled executable for the first time, Windows Defender SmartScreen may display a blue dialog stating "Windows protected your PC". Click on <strong>"More info"</strong> and then click <strong>"Run anyway"</strong> to launch the application.
                        </div>
                    </div>

                    <div class="faq-item">
                        <div class="faq-question" onclick="toggleFaq(this)">
                            <span>Do my changes sync automatically across devices?</span>
                            <svg class="faq-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polyline points="6 9 12 15 18 9"></polyline>
                            </svg>
                        </div>
                        <div class="faq-answer">
                            Yes! Capeonn uses a centralized Laravel API paired with live Pusher WebSockets. Any task status change, chat message, or time log submitted from Android or Windows is reflected across all connected sessions in real time.
                        </div>
                    </div>

                </div>
            </div>
        </section>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="footer-content">
                <div>
                    &copy; {{ date('Y') }} Capeonn Enterprise Platform. All rights reserved.
                </div>
                <div class="footer-links">
                    <a href="#features">Features</a>
                    <a href="#specs">Specifications</a>
                    <a href="#faq">Installation Guide</a>
                    <a href="{{ $exeInfo['download_url'] }}">Windows (.exe)</a>
                    <a href="{{ $apkInfo['download_url'] }}">Android (.apk)</a>
                </div>
            </div>
        </div>
    </footer>

    <!-- Interactive Toast Notification -->
    <div class="toast" id="download-toast">
        <svg class="toast-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
            <polyline points="22 4 12 14.01 9 11.01"></polyline>
        </svg>
        <span id="toast-message">Downloading file...</span>
    </div>

    <!-- Interactive Client Scripts -->
    <script>
        function handleDownload(platform, fileName) {
            const toast = document.getElementById('download-toast');
            const toastMsg = document.getElementById('toast-message');
            
            toastMsg.textContent = `Starting download for ${platform} (${fileName})...`;
            toast.classList.add('show');

            setTimeout(() => {
                toast.classList.remove('show');
            }, 4500);
        }

        function copyFolderPath() {
            const path = "{{ public_path('downloads') }}";
            navigator.clipboard.writeText(path).then(() => {
                const btnText = document.getElementById('copy-btn-text');
                const orig = btnText.textContent;
                btnText.textContent = "Copied!";
                setTimeout(() => {
                    btnText.textContent = orig;
                }, 2000);
            }).catch(err => {
                prompt("Folder path:", path);
            });
        }

        function toggleFaq(el) {
            const item = el.closest('.faq-item');
            const isActive = item.classList.contains('active');
            
            document.querySelectorAll('.faq-item').forEach(i => i.classList.remove('active'));
            
            if (!isActive) {
                item.classList.add('active');
            }
        }
    </script>
</body>
</html>
