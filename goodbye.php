<?php
// goodbye.php
require_once __DIR__ . '/config.php';
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Do zobaczenia – Kieszonkowe</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- PWA -->
    <link rel="manifest" href="/manifest.webmanifest">
    <meta name="theme-color" content="#0f172a">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="apple-touch-icon" href="/icons/icon-192.png">

    <!-- Twoje style -->
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="mobile.css">

    <style>
      /* Prosty fallback dla desktopu */
      .goodbye-container {
        max-width: 480px;
        margin: 40px auto;
        padding: 24px;
        border-radius: 16px;
        box-shadow: 0 12px 30px rgba(15, 23, 42, 0.18);
        background: #0f172a;
        color: #e5e7eb;
        font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        text-align: center;
      }
      .goodbye-title {
        font-size: 1.5rem;
        margin-bottom: 0.5rem;
      }
      .goodbye-text {
        font-size: 0.95rem;
        line-height: 1.4;
        margin-bottom: 1.5rem;
        color: #cbd5f5;
      }
      .goodbye-hint {
        font-size: 0.85rem;
        color: #9ca3af;
        margin-top: 1rem;
      }
      .goodbye-btn {
        display: inline-block;
        padding: 0.75rem 1.5rem;
        border-radius: 999px;
        text-decoration: none;
        font-weight: 600;
        border: none;
        cursor: pointer;
      }
      .goodbye-btn-primary {
        background: #22c55e;
        color: #022c22;
      }
      .goodbye-btn-primary:hover {
        filter: brightness(1.05);
      }

      /* Na telefonie użyjemy mobilnego layoutu .app, jeśli jest zdefiniowany w mobile.css */
      @media (max-width: 768px) {
        .goodbye-container--desktop {
          display: none;
        }
      }

      @media (min-width: 769px) {
        .app {
          display: none;
        }
      }
    </style>
</head>
<body>

    <!-- WERSJA MOBILNA – używa stylu .app z mobile.css -->
    <div class="app">
        <header class="app-header">
            <div class="app-header__left">
                <span class="app-logo">💰</span>
                <span class="app-title">Kieszonkowe</span>
            </div>
        </header>

        <section class="period-bar">
            <div class="period-main">Zostałeś wylogowany</div>
            <div class="period-sub">Możesz bezpiecznie zamknąć aplikację</div>
        </section>

        <main class="content">
            <h2 class="section-title">Do zobaczenia! 👋</h2>

            <p>
                Twoja sesja została zakończona.  
                Aby całkowicie zakończyć korzystanie z aplikacji:
            </p>
            <ul>
                <li>na telefonie – użyj przycisku <strong>Home</strong> lub gestu zamknięcia aplikacji,</li>
                <li>na komputerze – po prostu zamknij to okno.</li>
            </ul>

            <p style="margin-top: 1.5rem;">
                Jeśli chcesz, możesz zalogować się ponownie:
            </p>

            <p style="margin-top: 0.5rem;">
                <a href="login.php" class="btn btn-primary">Zaloguj ponownie</a>
            </p>
        </main>

        <nav class="bottom-nav" aria-label="Nawigacja dolna">
            <button class="bottom-nav__item bottom-nav__item--active" disabled>
                <span class="bottom-nav__icon">👋</span>
                <span class="bottom-nav__label">Wylogowano</span>
            </button>
            <button class="bottom-nav__item" onclick="window.location.href='login.php'">
                <span class="bottom-nav__icon">🔑</span>
                <span class="bottom-nav__label">Zaloguj</span>
            </button>
        </nav>
    </div>

    <!-- WERSJA DESKTOPOWA – prosty „card” pośrodku ekranu -->
    <div class="goodbye-container goodbye-container--desktop">
        <div class="goodbye-title">Zostałeś wylogowany</div>
        <p class="goodbye-text">
            Sesja w systemie <strong>Kieszonkowe dzieci</strong> została zakończona.
        </p>
        <p class="goodbye-text">
            Aby całkowicie zakończyć pracę:
            <br>– na telefonie, użyj przycisku <strong>Home</strong> lub gestu zamknięcia aplikacji,
            <br>– na komputerze, po prostu zamknij to okno.
        </p>

        <a href="login.php" class="goodbye-btn goodbye-btn-primary">Zaloguj ponownie</a>

        <div class="goodbye-hint">
            Po zalogowaniu zostaniesz ponownie przeniesiony do panelu głównego.
        </div>
    </div>

<script>
    // Rejestracja service workera (jak już masz)
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('/sw.js')
                .then(function (reg) {
                    console.log('Service Worker zarejestrowany (goodbye.php), scope:', reg.scope);
                })
                .catch(function (err) {
                    console.error('Błąd rejestracji SW (goodbye.php):', err);
                });
        });
    }

    // "Blokowanie" przycisku Wstecz wewnątrz aplikacji
    if (window.history && window.history.pushState) {
        // Dodajemy sztuczny wpis do historii
        history.pushState(null, '', location.href);

        window.addEventListener('popstate', function (event) {
            // Użytkownik wcisnął Wstecz na goodbye.php
            // – zamiast cofać się do poprzedniej strony, trzymamy go tu
            //   albo przekierowujemy na login.php.

            // Opcja 1: zostań na goodbye.php
            history.pushState(null, '', location.href);

            // Opcja 2: zamiast tego przejdź na login.php:
            // location.replace('login.php');
        });
    }
</script>

</body>
</html>
