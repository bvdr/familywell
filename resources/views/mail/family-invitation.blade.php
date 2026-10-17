{{-- "You're invited" (App\Mail\FamilyInvitationMail). Frame, colours and the always-white rules live in mail/layout. --}}
@extends('mail.layout', ['subject' => "You're invited to join {$familyName} on FamilyWell", 'preview' => "{$inviter} invited you to join {$familyName} on FamilyWell."])

@section('content')
    <p style="margin:0 0 14px; font-family:'Geist Mono', 'SF Mono', Menlo, Consolas, monospace; font-size:12px; font-weight:500; letter-spacing:1px; text-transform:uppercase; color:#5E5B4E;">
        Invitation
    </p>
    <h1 style="margin:0 0 12px; font-family:'Bricolage Grotesque', 'Helvetica Neue', Arial, sans-serif; font-weight:800; font-size:34px; line-height:1.1; letter-spacing:-1px; color:#121100;">
        <span class="gmail-screen"><span class="gmail-difference">You're invited</span></span>
    </h1>
    <p style="margin:0 0 28px; font-family:Inter, -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:17px; line-height:1.55; color:#2A2918;">
        {{ $inviter }} has invited you to join <strong>{{ $familyName }}</strong> on FamilyWell, a calm, shared space for your family's plans, lists and everyday moments.
    </p>

    {{-- The site's "sun" button: yellow, 2px ink outline, pill. --}}
    <table role="presentation" cellpadding="0" cellspacing="0" border="0">
        <tr>
            <td style="background:#FFDF00; background-image:linear-gradient(#FFDF00,#FFDF00); border:2px solid #121100; border-radius:999px;">
                <a href="{{ $acceptUrl }}" style="display:inline-block; padding:15px 26px; font-family:Inter, -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:16px; font-weight:700; color:#121100; text-decoration:none;"><span class="gmail-screen"><span class="gmail-difference">Accept invitation &rarr;</span></span></a>
            </td>
        </tr>
    </table>
@endsection

@section('footer')
    If the button doesn't work, paste this link into your browser:<br>
    <a href="{{ $acceptUrl }}" style="color:#121100; word-break:break-all;">{{ $acceptUrl }}</a>
@endsection
