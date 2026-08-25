<?php
/**
 * OtpEmailTemplate — branded HTML email for customer login OTP.
 *
 * v4.21.8 — replaces the plain-text version in ca_send_otp_email.
 *
 * Two outputs: HTML (for the multipart/alternative HTML part) and plain
 * text (for the fallback). The plain text is hand-written, NOT auto-stripped
 * from HTML, because email clients that fall through to plain text deserve
 * a clean experience too.
 *
 * Design constraints honored:
 *   - Single-table layout (avoids broken rendering on Outlook desktop)
 *   - All styles inline (Gmail strips <style> from body)
 *   - No remote images (no logo file — uses CSS for the brand mark)
 *   - Code displayed both in subject and body for fastest scan
 *   - 600px max-width (standard email-safe)
 *   - Dark-mode-friendly colors (no pure-black or pure-white surprises)
 */
declare(strict_types=1);

class OtpEmailTemplate
{
    public static function subject(string $code): string
    {
        return "DishNet Login Code: {$code}";
    }

    public static function html(string $firstName, string $code, int $ttlMinutes): string
    {
        $name = htmlspecialchars($firstName ?: 'there', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $codeEsc = htmlspecialchars($code, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $ttl = (int)$ttlMinutes;
        $year = date('Y');

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<title>DishNet Login Code</title>
</head>
<body style="margin:0;padding:0;background-color:#f5f5f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background-color:#f5f5f5;">
  <tr>
    <td align="center" style="padding:32px 16px;">
      <table role="presentation" cellpadding="0" cellspacing="0" width="600" style="max-width:600px;background-color:#ffffff;border-radius:14px;overflow:hidden;">

        <tr>
          <td style="padding:32px 32px 16px;">
            <span style="display:inline-block;font-family:'Helvetica Neue',Arial,sans-serif;font-weight:900;font-size:22px;letter-spacing:-0.4px;color:#141414;border-bottom:3px solid #D41C1C;padding-bottom:1px;">DishNet</span>
            <span style="display:inline-block;background-color:#141414;color:#ffffff;font-weight:800;font-size:9px;letter-spacing:0.12em;padding:3px 7px;border-radius:3px;margin-left:6px;vertical-align:5px;">AFRICA</span>
          </td>
        </tr>

        <tr>
          <td style="padding:0 32px;">
            <div style="height:3px;background:linear-gradient(110deg,#D41C1C 0%,#E8521A 60%,#FF7A35 100%);border-radius:2px;width:60px;margin:8px 0 24px;"></div>
          </td>
        </tr>

        <tr>
          <td style="padding:0 32px 4px;">
            <p style="margin:0;font-size:15px;color:#444444;line-height:1.55;">Hi {$name},</p>
          </td>
        </tr>

        <tr>
          <td style="padding:8px 32px 20px;">
            <p style="margin:0;font-size:14px;color:#666666;line-height:1.6;">
              Use the code below to log in to your DishNet customer portal:
            </p>
          </td>
        </tr>

        <tr>
          <td style="padding:0 32px 24px;">
            <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background-color:#fafafa;border:1.5px solid #f0f0f0;border-radius:12px;">
              <tr>
                <td align="center" style="padding:22px;">
                  <p style="margin:0 0 8px;font-size:11px;color:#888888;text-transform:uppercase;letter-spacing:0.08em;font-weight:600;">
                    Your login code
                  </p>
                  <p style="margin:0;font-family:'Menlo','Consolas','Courier New',monospace;font-size:32px;font-weight:700;color:#141414;letter-spacing:8px;">
                    {$codeEsc}
                  </p>
                  <p style="margin:12px 0 0;font-size:12px;color:#888888;">
                    Valid for {$ttl} minutes
                  </p>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <tr>
          <td style="padding:0 32px 24px;">
            <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background-color:#fff8e1;border:1px solid #ffe5a0;border-radius:8px;">
              <tr>
                <td style="padding:12px 14px;">
                  <p style="margin:0;font-size:12px;color:#7a5800;line-height:1.55;">
                    <strong style="color:#7a5800;">Didn't request this?</strong><br>
                    You can safely ignore this email. Your account is secure.
                  </p>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <tr>
          <td style="padding:18px 32px 28px;border-top:1px solid #eeeeee;">
            <p style="margin:0;font-size:11px;color:#999999;line-height:1.6;">
              <strong style="color:#666666;font-weight:600;">DishNet Africa Ltd.</strong> &middot; Juba, South Sudan<br>
              <a href="https://dishnetafrica.com" style="color:#D41C1C;text-decoration:none;">dishnetafrica.com</a>
              &middot;
              Support: <a href="https://wa.me/211921443009" style="color:#D41C1C;text-decoration:none;">+211 921 443 009</a>
            </p>
            <p style="margin:14px 0 0;font-size:10px;color:#bbbbbb;line-height:1.5;">
              &copy; {$year} DishNet Africa Ltd. This is an automated message — please do not reply.
            </p>
          </td>
        </tr>

      </table>
    </td>
  </tr>
</table>
</body>
</html>
HTML;
    }

    public static function text(string $firstName, string $code, int $ttlMinutes): string
    {
        $name = $firstName ?: 'there';
        $ttl = (int)$ttlMinutes;
        return
            "Hi {$name},\r\n\r\n" .
            "Your DishNet login code is:\r\n\r\n" .
            "    {$code}\r\n\r\n" .
            "This code is valid for {$ttl} minutes.\r\n\r\n" .
            "If you did not request this code, please ignore this email — your account is secure.\r\n\r\n" .
            "— DishNet Africa\r\n" .
            "https://dishnetafrica.com\r\n" .
            "Support: +211 921 443 009\r\n";
    }
}
