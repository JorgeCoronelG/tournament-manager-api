<?php

namespace Tests\Feature\Mail;

use App\Models\User;
use App\Notifications\PasswordResetCodeNotification;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\Messages\MailMessage;
use Tests\TestCase;

class MailLayoutTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.name' => 'Velto',
            'app.url' => 'https://api.velto.test/',
            'app.frontend_url' => 'https://panel.velto.test',
        ]);
    }

    public function test_header_shows_both_logos_with_absolute_urls_based_on_app_url(): void
    {
        $html = $this->renderPasswordResetMail();

        foreach (['logo-light', 'logo-dark'] as $logo) {
            $this->assertMatchesRegularExpression(
                '#<img src="https://api\.velto\.test/img/mail/'.$logo.'\.png" class="logo '.$logo.'" alt="Velto"#',
                $html
            );
            $this->assertFileExists(public_path("img/mail/{$logo}.png"));
        }

        $this->assertStringNotContainsString('laravel.com/img/notification-logo', $html);
    }

    public function test_header_logo_is_not_a_link_and_does_not_expose_the_panel_url(): void
    {
        $html = $this->renderPasswordResetMail();

        $this->assertStringNotContainsString('<a ', $html);
        $this->assertStringNotContainsString('panel.velto.test', $html);
    }

    public function test_dark_logo_is_hidden_by_default_and_dark_styles_survive_inlining(): void
    {
        $html = $this->renderPasswordResetMail();

        $this->assertMatchesRegularExpression('#class="logo logo-dark"[^>]*style="[^"]*display: ?none#', $html);
        $this->assertStringContainsString('@media (prefers-color-scheme: dark)', $html);
        $this->assertStringContainsString('<meta name="color-scheme" content="light dark">', $html);
    }

    public function test_plain_text_version_renders_without_a_header_url(): void
    {
        $mail = (new PasswordResetCodeNotification('123456', 15))->toMail(new User);

        $text = (string) app(Markdown::class)->renderText((string) $mail->markdown, $mail->data());

        $this->assertStringContainsString('123456', $text);
        $this->assertStringNotContainsString('api.velto.test', $text);
        $this->assertStringNotContainsString('panel.velto.test', $text);
    }

    public function test_button_and_links_use_the_brand_color_after_inlining(): void
    {
        $html = (string) (new MailMessage)
            ->line('[Ayuda](https://panel.velto.test/ayuda)')
            ->action('Activar cuenta', 'https://panel.velto.test/activar')
            ->render();

        $this->assertMatchesRegularExpression('#class="button button-primary"[^>]*style="[^"]*background-color: \#0E7A3D;[^"]*; color: \#ffffff;#i', $html);
        $this->assertMatchesRegularExpression('#href="https://panel\.velto\.test/ayuda" style="[^"]*; color: \#0E7A3D;#i', $html);
        $this->assertStringContainsString('background-color: #599B6A !important;', $html);
    }

    private function renderPasswordResetMail(): string
    {
        return (string) (new PasswordResetCodeNotification('123456', 15))->toMail(new User)->render();
    }
}
