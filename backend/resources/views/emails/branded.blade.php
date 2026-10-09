{{--
  Branded HTML email used by every system email (CRM messages, invoices, reminders, digest, invites, password resets).
  Table layout + inline styles so it renders in Gmail, Outlook and phone mail apps. The colour logo is embedded
  (CID) so it shows even when the recipient's mail app blocks remote images.
  Variables: $body (plain text, line breaks kept), optional $heading, $actionUrl, $actionText, $note.
--}}
@php
    $brand = \App\Models\Setting::current();
    $logo = isset($message) && is_file(public_path('assets/logo.png')) ? $message->embed(public_path('assets/logo.png')) : url('/assets/logo.png');
    // Escape, keep line breaks, and turn bare links into clickable ones.
    $html = nl2br(preg_replace('~(https?://[^\s<]+)~', '<a href="$1" style="color:#7a1528;word-break:break-all">$1</a>', e($body ?? '')));
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<title>{{ $heading ?? $brand->company_name }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f1ee;font-family:'Segoe UI',Helvetica,Arial,sans-serif;color:#1c1a19;-webkit-text-size-adjust:100%">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f1ee">
  <tr><td align="center" style="padding:28px 12px">
    <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e8e3e0">
      <tr><td style="height:5px;background:#7a1528;font-size:0;line-height:0">&nbsp;</td></tr>
      <tr><td align="center" style="padding:28px 32px 18px;border-bottom:1px solid #f1ede9">
        <img src="{{ $logo }}" alt="{{ $brand->company_name }}" width="200" style="display:block;width:200px;max-width:70%;height:auto;border:0">
      </td></tr>
      <tr><td style="padding:28px 32px 8px">
        @isset($heading)
          <h1 style="margin:0 0 16px;font-size:20px;line-height:1.3;font-weight:700;color:#1c1a19">{{ $heading }}</h1>
        @endisset
        <div style="font-size:15px;line-height:1.6;color:#1c1a19">{!! $html !!}</div>
        @isset($actionUrl)
          <table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px 0 8px"><tr>
            <td style="background:#7a1528;border-radius:8px">
              <a href="{{ $actionUrl }}" style="display:inline-block;padding:13px 26px;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none">{{ $actionText ?? 'Open' }}</a>
            </td>
          </tr></table>
          <p style="margin:12px 0 0;font-size:12px;line-height:1.5;color:#6b6660">Button not working? Copy this link into your browser:<br><a href="{{ $actionUrl }}" style="color:#7a1528;word-break:break-all">{{ $actionUrl }}</a></p>
        @endisset
        @isset($note)
          <p style="margin:18px 0 0;font-size:13px;line-height:1.5;color:#6b6660">{{ $note }}</p>
        @endisset
      </td></tr>
      <tr><td style="padding:22px 32px 26px">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-top:1px solid #f1ede9"><tr>
          <td style="padding-top:16px;font-size:12px;line-height:1.5;color:#9a948e">
            <strong style="color:#7a1528">{{ $brand->company_name }}</strong>@if($brand->vat_number) · VAT {{ $brand->vat_number }}@endif<br>
            People · Property · Passion<br>
            Sent by {{ $brand->system_name ?: 'Morena CRM' }}
          </td>
        </tr></table>
      </td></tr>
    </table>
  </td></tr>
</table>
</body>
</html>
