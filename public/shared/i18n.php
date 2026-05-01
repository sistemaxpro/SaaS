<?php

if (!defined('SISTEMAX_V1')) {
    die('Acceso no autorizado');
}

final class SmxI18n
{
    private const DEFAULT_LOCALE = 'es';
    private const COOKIE_NAME = 'smx_locale';
    private const SESSION_KEY = 'locale';

    private static ?string $locale = null;
    private static ?array $messagesCache = null;
    private static ?bool $dbOverridesTableExists = null;

    public static function bootstrap(): void
    {
        $requested = self::normalizeLocale($_POST['locale'] ?? $_GET['locale'] ?? null);
        if ($requested !== null) {
            self::setLocale($requested);
            return;
        }

        if (self::$locale !== null) {
            return;
        }

        $sessionLocale = self::normalizeLocale($_SESSION[self::SESSION_KEY] ?? null);
        if ($sessionLocale !== null) {
            self::$locale = $sessionLocale;
            return;
        }

        $cookieLocale = self::normalizeLocale($_COOKIE[self::COOKIE_NAME] ?? null);
        if ($cookieLocale !== null) {
            self::setLocale($cookieLocale, false);
            return;
        }

        self::setLocale(self::detectBrowserLocale(), false);
    }

    public static function getLocale(): string
    {
        if (self::$locale === null) {
            self::bootstrap();
        }

        return self::$locale ?? self::DEFAULT_LOCALE;
    }

    public static function setLocale(?string $locale, bool $persistCookie = true): string
    {
        $normalized = self::normalizeLocale($locale) ?? self::DEFAULT_LOCALE;
        self::$locale = $normalized;
        $_SESSION[self::SESSION_KEY] = $normalized;

        if ($persistCookie && !headers_sent()) {
            setcookie(self::COOKIE_NAME, $normalized, [
                'expires' => time() + (86400 * 365),
                'path' => '/',
                'domain' => '',
                'secure' => !empty($_SERVER['HTTPS']),
                'httponly' => false,
                'samesite' => 'Lax',
            ]);
        }

        return $normalized;
    }

    public static function normalizeLocale(?string $locale): ?string
    {
        $locale = strtolower(trim((string)$locale));
        if ($locale === '') {
            return null;
        }

        if (str_starts_with($locale, 'pt')) {
            return 'pt';
        }
        if (str_starts_with($locale, 'en')) {
            return 'en';
        }
        if (str_starts_with($locale, 'es')) {
            return 'es';
        }

        return null;
    }

    public static function detectBrowserLocale(): string
    {
        $accept = (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
        foreach (preg_split('/,/', $accept) as $part) {
            $lang = self::normalizeLocale(explode(';', $part)[0] ?? '');
            if ($lang !== null) {
                return $lang;
            }
        }

        return self::DEFAULT_LOCALE;
    }

    public static function getLocaleOptions(): array
    {
        return [
            ['code' => 'es', 'label' => 'Español', 'native' => 'ES'],
            ['code' => 'en', 'label' => 'English', 'native' => 'EN'],
            ['code' => 'pt', 'label' => 'Português', 'native' => 'PT'],
        ];
    }

    public static function config(): array
    {
        $locale = self::getLocale();
        return [
            'locale' => $locale,
            'supported' => array_map(
                static fn(array $opt): array => ['code' => $opt['code'], 'label' => $opt['label']],
                self::getLocaleOptions()
            ),
            'messages' => self::getMessagesForLocale($locale),
            'uiMap' => self::buildClientTranslationMap($locale),
        ];
    }

    public static function t(string $key, array $replace = [], ?string $locale = null): string
    {
        $lang = self::normalizeLocale($locale) ?? self::getLocale();
        $messages = self::messages();
        $value = self::findByDotKey($messages[$lang] ?? [], $key);

        if (!is_string($value) || $value === '') {
            $value = self::findByDotKey($messages[self::DEFAULT_LOCALE] ?? [], $key);
        }

        $text = is_string($value) ? $value : $key;
        foreach ($replace as $token => $replacement) {
            $text = str_replace(':' . $token, (string)$replacement, $text);
        }

        return $text;
    }

    public static function tApp(string $code, string $fallback = '', ?string $locale = null): string
    {
        $code = trim($code);
        if ($code === '') {
            return $fallback;
        }

        $candidates = [];
        if (str_contains($code, '.')) {
            $candidates[] = $code;
        } else {
            $candidates[] = 'menu.' . $code;
            $candidates[] = 'menu_apps.' . $code;
        }

        foreach ($candidates as $candidate) {
            $translated = self::t($candidate, [], $locale);
            if ($translated !== $candidate) {
                return $translated;
            }
        }

        return $fallback;
    }

    public static function getMessagesForLocale(?string $locale = null): array
    {
        $lang = self::normalizeLocale($locale) ?? self::getLocale();
        $messages = self::messages();
        return $messages[$lang] ?? [];
    }

    public static function flattenLocaleMessages(?string $locale = null): array
    {
        return self::flattenMessages(self::getMessagesForLocale($locale));
    }

    private static function findByDotKey(array $messages, string $key)
    {
        $value = $messages;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    private static function messages(): array
    {
        if (self::$messagesCache !== null) {
            return self::$messagesCache;
        }

        $basePath = dirname(__DIR__) . '/lang';
        $messages = [];
        foreach (['es', 'en', 'pt'] as $locale) {
            $file = $basePath . '/' . $locale . '.php';
            $messages[$locale] = is_file($file) ? (require $file) : [];
        }

        $overridesFile = $basePath . '/overrides.php';
        $overrides = is_file($overridesFile) ? (require $overridesFile) : [];
        foreach ($overrides as $locale => $overrideMessages) {
            if (!isset($messages[$locale]) || !is_array($overrideMessages)) {
                continue;
            }
            $messages[$locale] = self::mergeMessages($messages[$locale], $overrideMessages);
        }

        $dbOverrides = self::loadDbOverrides();
        foreach ($dbOverrides as $locale => $overrideMessages) {
            if (!isset($messages[$locale]) || !is_array($overrideMessages)) {
                continue;
            }
            $messages[$locale] = self::mergeMessages($messages[$locale], $overrideMessages);
        }

        self::$messagesCache = $messages;
        return self::$messagesCache;
    }

    private static function mergeMessages(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = self::mergeMessages($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }

    private static function flattenMessages(array $messages, string $prefix = ''): array
    {
        $flat = [];
        foreach ($messages as $key => $value) {
            $fullKey = $prefix === '' ? (string)$key : ($prefix . '.' . $key);
            if (is_array($value)) {
                $flat += self::flattenMessages($value, $fullKey);
            } else {
                $flat[$fullKey] = (string)$value;
            }
        }
        ksort($flat);
        return $flat;
    }

    private static function buildClientTranslationMap(?string $locale = null): array
    {
        $lang = self::normalizeLocale($locale) ?? self::getLocale();
        if ($lang === self::DEFAULT_LOCALE) {
            return [];
        }

        $base = self::flattenLocaleMessages(self::DEFAULT_LOCALE);
        $target = self::flattenLocaleMessages($lang);
        $map = [];

        foreach ($base as $key => $sourceText) {
            $sourceText = trim((string)$sourceText);
            $translatedText = trim((string)($target[$key] ?? ''));
            if ($sourceText === '' || $translatedText === '' || $sourceText === $translatedText) {
                continue;
            }

            // Mantener la primera traducción encontrada para evitar que textos
            // ambiguos sobrescriban decisiones más específicas del diccionario.
            if (!isset($map[$sourceText])) {
                $map[$sourceText] = $translatedText;
            }

            $asciiSource = self::asciiText($sourceText);
            if ($asciiSource !== '' && $asciiSource !== $sourceText && !isset($map[$asciiSource])) {
                $map[$asciiSource] = $translatedText;
            }
        }

        return $map;
    }

    private static function asciiText(string $value): string
    {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        return trim(is_string($ascii) ? $ascii : '');
    }

    private static function loadDbOverrides(): array
    {
        if (!self::dbOverridesTableExists()) {
            return [];
        }

        try {
            $db = Database::getMasterConnection();
            $sql = "SELECT locale, message_key, override_value
                    FROM " . MASTER_DB . ".smx_i18n_overrides
                    WHERE active = 1";
            $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $messages = [];
            foreach ($rows as $row) {
                $locale = self::normalizeLocale($row['locale'] ?? null);
                $messageKey = trim((string)($row['message_key'] ?? ''));
                $overrideValue = (string)($row['override_value'] ?? '');
                if ($locale === null || $messageKey === '') {
                    continue;
                }
                if (!isset($messages[$locale]) || !is_array($messages[$locale])) {
                    $messages[$locale] = [];
                }
                self::setNestedValue($messages[$locale], $messageKey, $overrideValue);

                // Compatibilidad: el menú usa claves en `menu.*` y `menu_apps.*`.
                // Un override guardado en cualquiera de los dos namespaces debe
                // reflejarse en ambos para que el render tome el valor corregido.
                if (str_starts_with($messageKey, 'menu_apps.')) {
                    self::setNestedValue($messages[$locale], 'menu.' . substr($messageKey, 10), $overrideValue);
                } elseif (str_starts_with($messageKey, 'menu.')) {
                    self::setNestedValue($messages[$locale], 'menu_apps.' . substr($messageKey, 5), $overrideValue);
                }
            }
            return $messages;
        } catch (Throwable $e) {
            error_log('[SmxI18n] loadDbOverrides: ' . $e->getMessage());
            return [];
        }
    }

    public static function ensureDbOverridesTable(): bool
    {
        try {
            $db = Database::getMasterConnection();
            $db->exec("
                CREATE TABLE IF NOT EXISTS " . MASTER_DB . ".smx_i18n_overrides (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    locale VARCHAR(5) NOT NULL,
                    message_key VARCHAR(190) NOT NULL,
                    override_value TEXT NOT NULL,
                    notes VARCHAR(255) NULL,
                    active TINYINT(1) NOT NULL DEFAULT 1,
                    created_by INT NULL,
                    updated_by INT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY uq_locale_message_key (locale, message_key),
                    KEY idx_active (active),
                    KEY idx_message_key (message_key)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            self::$dbOverridesTableExists = true;
            return true;
        } catch (Throwable $e) {
            error_log('[SmxI18n] ensureDbOverridesTable: ' . $e->getMessage());
            self::$dbOverridesTableExists = false;
            return false;
        }
    }

    private static function dbOverridesTableExists(): bool
    {
        if (self::$dbOverridesTableExists !== null) {
            return self::$dbOverridesTableExists;
        }

        try {
            $db = Database::getMasterConnection();
            $stmt = $db->prepare("
                SELECT 1
                FROM information_schema.tables
                WHERE table_schema = ?
                  AND table_name = 'smx_i18n_overrides'
                LIMIT 1
            ");
            $stmt->execute([MASTER_DB]);
            self::$dbOverridesTableExists = (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            self::$dbOverridesTableExists = false;
        }

        return self::$dbOverridesTableExists;
    }

    private static function setNestedValue(array &$target, string $dotKey, string $value): void
    {
        $segments = array_values(array_filter(explode('.', $dotKey), static fn($item) => $item !== ''));
        if (empty($segments)) {
            return;
        }

        $cursor = &$target;
        foreach ($segments as $index => $segment) {
            $isLast = $index === array_key_last($segments);
            if ($isLast) {
                $cursor[$segment] = $value;
                return;
            }
            if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }
            $cursor = &$cursor[$segment];
        }
    }
}

function t(string $key, array $replace = [], ?string $locale = null): string
{
    return SmxI18n::t($key, $replace, $locale);
}

function t_app(string $code, string $fallback = '', ?string $locale = null): string
{
    return SmxI18n::tApp($code, $fallback, $locale);
}
