<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ApiResetPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(public string $token)
    {
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    // منبني رابط استعادة كلمة السر يدوياً (بدون route() تبع الويب لأنو
    // هاد نظام API فقط)، وبيوجّه لصفحة الفرونت إند المحددة بـ FRONTEND_URL
    // أو بيرجع التوكن مباشرة إذا ما كان في فرونت إند معرّف.
    public function toMail($notifiable): MailMessage
    {
        $frontendUrl = rtrim(config('app.frontend_url', ''), '/');

        $resetUrl = $frontendUrl !== ''
            ? $frontendUrl . '/reset-password?token=' . $this->token . '&email=' . urlencode($notifiable->getEmailForPasswordReset())
            : null;

        $message = (new MailMessage)
            ->subject('طلب استعادة كلمة السر')
            ->line('وصلنا طلب لاستعادة كلمة السر لحسابك.');

        if ($resetUrl) {
            $message->action('إعادة تعيين كلمة السر', $resetUrl);
        } else {
            $message->line('استخدم هاد التوكن مع الإيميل تبعك بطلب POST /api/reset-password:')
                ->line($this->token);
        }

        return $message->line('إذا ما كنت انت اللي طلبت استعادة كلمة السر، تجاهل هاد الإيميل.');
    }
}
