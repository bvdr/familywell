{{-- Shared frame for every FamilyWell email, styled after familywell.org: ink #121100, sun #FFDF00 with a 2px ink
     outline (the site's .btn-sun), line #E2DED1, muted #5E5B4E; Bricolage Grotesque display, Inter body, Geist Mono
     labels (web fonts load in Apple Mail / iOS; elsewhere each stack's system fonts take over).

     ALWAYS WHITE, on purpose (product decision): the email must look the same in a dark-mode inbox. Tested through
     the real mailer in Spark on iOS AND macOS dark mode (2026-10-18); only this combination held on both:
     - every coloured box paints its colour twice: background-color AND a flat linear-gradient. Spark recolours
       background colours but leaves background images (gradients) alone.
     - near-black text carries -webkit-text-fill-color with its colour: Spark lightens `color`, but in WebKit the
       fill colour wins, so the text stays dark.
     - color-scheme "light only" (meta + :root) for clients that read it (Apple Mail).
     Not enough on desktop Spark: an @media (prefers-color-scheme: dark) block (fixed iOS only). Washed text out:
     Gmail-app blend-mode wrappers. Test sends must go through the real mailer: the Gmail send tool strips <head>.
     Every coloured box carries an fw-* class: fw-page, fw-card, fw-sun (yellow), fw-ink (near-black text).
     The logo PNG's letters carry a white halo, a fallback for any client that still darkens the page.
     Email-client HTML: tables + inline styles (Gmail drops much of <style>, Outlook ignores flex/grid), PNG not SVG.

     Slots: $subject, $preview (inbox preview line), $locale, $logo; @yield('content') goes inside the white card. --}}
<!DOCTYPE html>
<html lang="{{ $locale ?? 'en' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light only">
    <meta name="supported-color-schemes" content="light only">
    <title>{{ $subject }}</title>
    <style>
        :root { color-scheme: light only; supported-color-schemes: light only; }
    </style>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@96,700..800&family=Geist+Mono:wght@500;700&family=Inter:wght@400;600&display=swap" rel="stylesheet">
</head>
<body class="body" style="margin:0; padding:0; background:#FFFFFF; background-image:linear-gradient(#FFFFFF,#FFFFFF); -webkit-text-size-adjust:100%;">
    @isset($preview)
        <div style="display:none; max-height:0; overflow:hidden; opacity:0; mso-hide:all;">{{ $preview }}&#8199;&#65279;&#847;&#8199;&#65279;&#847;&#8199;&#65279;&#847;</div>
    @endisset

    <table class="fw-page" role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#FFFFFF; background-image:linear-gradient(#FFFFFF,#FFFFFF);">
        <tr>
            <td class="fw-page" align="center" style="padding:40px 16px 48px; background:#FFFFFF; background-image:linear-gradient(#FFFFFF,#FFFFFF);">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:520px;">
                    <tr>
                        <td style="padding:0 4px 28px;">
                            <img src="{{ $logo ?? asset('images/email/logo.png') }}" width="162" height="36" alt="FamilyWell" style="display:block; border:0;">
                        </td>
                    </tr>
                    <tr>
                        <td class="fw-card" style="background:#FFFFFF; background-image:linear-gradient(#FFFFFF,#FFFFFF); border:1px solid #E2DED1; border-radius:24px; padding:36px 32px 32px;">
                            @yield('content')
                        </td>
                    </tr>
                    @hasSection('footer')
                        <tr>
                            <td style="padding:24px 4px 0; font-family:Inter, -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:13px; line-height:1.6; color:#5E5B4E;">
                                @yield('footer')
                            </td>
                        </tr>
                    @endif
                    <tr>
                        <td style="padding:16px 4px 0; font-family:'Geist Mono', 'SF Mono', Menlo, Consolas, monospace; font-size:12px; letter-spacing:0.5px; color:#8F8B76;">
                            <a class="fw-ink" href="https://familywell.org" style="color:#121100; -webkit-text-fill-color:#121100; text-decoration:none; font-weight:700;">familywell.org</a>@isset($tagline)<br>{{ $tagline }}@endisset
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
