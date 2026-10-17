{{-- The sign-in / sign-up code email (App\Notifications\LoginCode), styled after familywell.org: paper #F7F5EE,
     ink #121100, sun #FFDF00 with a 2px ink outline (the site's .btn-sun), line #E2DED1, muted #5E5B4E;
     Bricolage Grotesque display, Inter body, Geist Mono labels. Email-client HTML: tables + inline styles
     (Gmail drops most <style>, Outlook ignores flex/grid), PNG logo (SVG is blocked). The web fonts load in
     Apple Mail / iOS; elsewhere the system-font fallbacks in each stack take over. --}}
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light only">
    <meta name="supported-color-schemes" content="light only">
    <title>{{ $subject }}</title>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@96,700..800&family=Geist+Mono:wght@500;700&family=Inter:wght@400;600&display=swap" rel="stylesheet">
</head>
<body style="margin:0; padding:0; background:#F7F5EE; -webkit-text-size-adjust:100%;">
    {{-- Inbox preview line: the code itself, so it can be read without opening the email. --}}
    <div style="display:none; max-height:0; overflow:hidden; opacity:0; mso-hide:all;">{{ $preview }}&#8199;&#65279;&#847;&#8199;&#65279;&#847;&#8199;&#65279;&#847;</div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#F7F5EE;">
        <tr>
            <td align="center" style="padding:40px 16px 48px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:520px;">
                    {{-- Logo: familywell.org's own header logo (img/logo.svg) as a PNG, so the wordmark looks the
                         same in every client; text in a web font only shows in Apple Mail / iOS. --}}
                    <tr>
                        <td style="padding:0 4px 28px;">
                            <img src="{{ $logo }}" width="158" height="32" alt="FamilyWell" style="display:block; border:0;">
                        </td>
                    </tr>

                    {{-- Card --}}
                    <tr>
                        <td style="background:#FFFFFF; border:1px solid #E2DED1; border-radius:24px; padding:36px 32px 32px;">
                            {{-- Eyebrow, like the site's mono labels --}}
                            <p style="margin:0 0 14px; font-family:'Geist Mono', 'SF Mono', Menlo, Consolas, monospace; font-size:12px; font-weight:500; letter-spacing:1px; text-transform:uppercase; color:#5E5B4E;">
                                {{ $eyebrow }}
                            </p>
                            <h1 style="margin:0 0 12px; font-family:'Bricolage Grotesque', 'Helvetica Neue', Arial, sans-serif; font-weight:800; font-size:34px; line-height:1.1; letter-spacing:-1px; color:#121100;">
                                {{ $heading }}
                            </h1>
                            <p style="margin:0 0 28px; font-family:Inter, -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:17px; line-height:1.55; color:#2A2918;">
                                {{ $intro }}
                            </p>

                            {{-- The code, on the site's "sun" block: yellow with a 2px ink outline. --}}
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td align="center" style="background:#FFDF00; border:2px solid #121100; border-radius:20px; padding:22px 8px;">
                                        <span style="font-family:'Geist Mono', 'SF Mono', Menlo, Consolas, 'Courier New', monospace; font-size:44px; line-height:1; font-weight:700; letter-spacing:10px; color:#121100; padding-left:10px;">{{ $code }}</span>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin:22px 0 0; font-family:Inter, -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:15px; line-height:1.55; color:#5E5B4E;">
                                &#10003;&nbsp; {{ $expires }}
                            </p>
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td style="padding:24px 4px 0; font-family:Inter, -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:13px; line-height:1.6; color:#5E5B4E;">
                            {{ $ignore }}
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 4px 0; font-family:'Geist Mono', 'SF Mono', Menlo, Consolas, monospace; font-size:12px; letter-spacing:0.5px; color:#8F8B76;">
                            <a href="https://familywell.org" style="color:#121100; text-decoration:none; font-weight:700;">familywell.org</a><br>{{ $tagline }}
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
