<?php

declare(strict_types=1);

namespace Grav\Common {
    abstract class Plugin
    {
        protected mixed $grav = null;
        protected mixed $config = null;

        public function setContractContext(mixed $grav, mixed $config): void
        {
            $this->grav = $grav;
            $this->config = $config;
        }
    }
}

namespace RocketTheme\Toolbox\Event {
    use ArrayAccess;

    final class Event implements ArrayAccess
    {
        /** @param array<string, mixed> $data */
        public function __construct(private array $data = [])
        {
        }

        public function offsetExists(mixed $offset): bool
        {
            return array_key_exists((string) $offset, $this->data);
        }

        public function offsetGet(mixed $offset): mixed
        {
            return $this->data[(string) $offset] ?? null;
        }

        public function offsetSet(mixed $offset, mixed $value): void
        {
            $this->data[(string) $offset] = $value;
        }

        public function offsetUnset(mixed $offset): void
        {
            unset($this->data[(string) $offset]);
        }
    }
}

namespace GravCaxtonContract {
    use ArrayAccess;
    use Closure;
    use Grav\Plugin\GravCaxton\Contracts\CaxtonExtensionInterface;
    use Grav\Plugin\GravCaxton\Contracts\CaxtonServiceInterface;
    use Grav\Plugin\GravCaxton\Contracts\Exception\BlockNotEditableException;
    use Grav\Plugin\GravCaxton\Contracts\Exception\DuplicateExtensionException;
    use Grav\Plugin\GravCaxton\Contracts\Exception\InvalidSourceException;
    use Grav\Plugin\GravCaxton\Contracts\Exception\SourceTooLargeException;
    use Grav\Plugin\GravCaxton\Contracts\Exception\StaleSourceException;
    use Grav\Plugin\GravCaxton\Extension\ExtensionRegistry;
    use RuntimeException;
    use Throwable;

    $pluginDirectory = getenv('GRAV_CAXTON_PLUGIN_DIR');
    if (!is_string($pluginDirectory) || $pluginDirectory === '') {
        $pluginDirectory = dirname(__DIR__, 2) . '/plugins/grav-caxton';
    }
    $pluginDirectory = rtrim($pluginDirectory, '/');
    $pluginFile = $pluginDirectory . '/grav-caxton.php';
    if (!is_file($pluginFile)) {
        fwrite(STDERR, "Caxton plugin entry point not found.\n");
        exit(1);
    }

    spl_autoload_register(static function (string $class) use ($pluginDirectory): void {
        $prefix = 'Grav\\Plugin\\GravCaxton\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $file = $pluginDirectory . '/classes/'
            . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    });
    require_once $pluginFile;

    final class ContractConfig
    {
        /** @param array<string, mixed> $values */
        public function __construct(private readonly array $values = [])
        {
        }

        public function get(string $key, mixed $default = null): mixed
        {
            return $this->values[$key] ?? $default;
        }
    }

    final class ContractLogger
    {
        /** @var list<string> */
        public array $errors = [];

        public function error(string $message): void
        {
            $this->errors[] = $message;
        }
    }

    final class ContractContainer implements ArrayAccess
    {
        /** @var array<string, mixed> */
        private array $values = [];
        public ?Closure $eventListener = null;
        /** @var list<string> */
        public array $events = [];

        public function offsetExists(mixed $offset): bool
        {
            return array_key_exists((string) $offset, $this->values);
        }

        public function offsetGet(mixed $offset): mixed
        {
            $key = (string) $offset;
            $value = $this->values[$key] ?? null;
            if ($value instanceof Closure) {
                $value = $value();
                $this->values[$key] = $value;
            }
            return $value;
        }

        public function offsetSet(mixed $offset, mixed $value): void
        {
            $this->values[(string) $offset] = $value;
        }

        public function offsetUnset(mixed $offset): void
        {
            unset($this->values[(string) $offset]);
        }

        public function fireEvent(string $name, object $event): void
        {
            $this->events[] = $name;
            if ($this->eventListener !== null) {
                ($this->eventListener)($name, $event);
            }
        }
    }

    final readonly class FakeExtension implements CaxtonExtensionInterface
    {
        public function __construct(private string $extensionId)
        {
        }

        public function id(): string
        {
            return $this->extensionId;
        }

        public function version(): string
        {
            return '1.0.0';
        }

        public function capabilities(): array
        {
            return ['describe-construct'];
        }

        public function constructTypes(): array
        {
            return ['fixture/notice'];
        }
    }

    final readonly class FakeUser
    {
        /** @param list<string> $permissions */
        public function __construct(private array $permissions)
        {
        }

        public function authorize(string $permission): bool
        {
            return in_array($permission, $this->permissions, true);
        }
    }

    function expect(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    function expectSame(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException($message);
        }
    }

    /** @param class-string<Throwable> $class */
    function expectThrows(string $class, callable $callback, string $message): Throwable
    {
        try {
            $callback();
        } catch (Throwable $error) {
            if ($error instanceof $class) {
                return $error;
            }
            throw new RuntimeException($message . ' Wrong exception: ' . $error::class);
        }
        throw new RuntimeException($message . ' No exception was thrown.');
    }

    $container = new ContractContainer();
    $container['log'] = new ContractLogger();
    $container->eventListener = static function (string $name, object $event): void {
        if ($name === \Grav\Plugin\GravCaxtonPlugin::EXTENSION_EVENT) {
            $event['registry']->register(new FakeExtension('fixture/notice'));
        }
    };
    $plugin = new \Grav\Plugin\GravCaxtonPlugin();
    $plugin->setContractContext($container, new ContractConfig());
    $plugin->autoload();
    $plugin->onPluginsInitialized();

    expect(
        isset(\Grav\Plugin\GravCaxtonPlugin::getSubscribedEvents()['onApiBlueprintResolved']),
        'The Admin2 blueprint event must be subscribed.'
    );
    $pageBlueprint = new \RocketTheme\Toolbox\Event\Event([
        'context' => 'page',
        'user' => new FakeUser(['grav-caxton.use', 'grav-caxton.source']),
        'fields' => [[
            'type' => 'tabs',
            'fields' => [[
                'type' => 'tab',
                'fields' => [
                    ['name' => 'content', 'type' => 'markdown', 'label' => 'Content'],
                    ['name' => 'header.custom_code', 'type' => 'editor', 'label' => 'Code'],
                ],
            ]],
        ]],
    ]);
    $plugin->onApiBlueprintResolved($pageBlueprint);
    $resolved = $pageBlueprint['fields'][0]['fields'][0]['fields'];
    expectSame('caxton', $resolved[0]['type'], 'Permitted page Markdown fields must become Caxton fields.');
    expectSame(true, $resolved[0]['caxton']['allow_source'], 'Source permission must reach the field.');
    expectSame(
        ['undo', 'redo', 'separator', 'heading', 'separator', 'bold', 'italic', 'strikethrough', 'inline_code',
            'remove_format', 'separator', 'link', 'blockquote', 'bullet_list', 'ordered_list',
            'code_block', 'separator', 'source'],
        $resolved[0]['caxton']['toolbar'],
        'The bounded default toolbar must reach the field in its configured order.'
    );
    expectSame('editor', $resolved[1]['type'], 'Explicit code-editor fields must remain untouched.');

    $deniedBlueprint = new \RocketTheme\Toolbox\Event\Event([
        'context' => 'page',
        'user' => new FakeUser([]),
        'fields' => [['name' => 'content', 'type' => 'markdown']],
    ]);
    $plugin->onApiBlueprintResolved($deniedBlueprint);
    expectSame('markdown', $deniedBlueprint['fields'][0]['type'], 'Denied users must retain the normal Markdown field.');

    $noSourceBlueprint = new \RocketTheme\Toolbox\Event\Event([
        'context' => 'page',
        'user' => new FakeUser(['grav-caxton.use']),
        'fields' => [['name' => 'content', 'type' => 'markdown']],
    ]);
    $plugin->onApiBlueprintResolved($noSourceBlueprint);
    expectSame(false, $noSourceBlueprint['fields'][0]['caxton']['allow_source'], 'Source mode needs its separate permission.');
    expect(
        !in_array('source', $noSourceBlueprint['fields'][0]['caxton']['toolbar'], true),
        'Source mode must be removed from the toolbar without source permission.'
    );

    $customToolbarPlugin = new \Grav\Plugin\GravCaxtonPlugin();
    $customToolbarPlugin->setContractContext(
        new ContractContainer(),
        new ContractConfig([
            'plugins.grav-caxton.admin.toolbar' => 'link,|,blockquote,unknown,bulleList,bulletList,|',
        ])
    );
    $customToolbarBlueprint = new \RocketTheme\Toolbox\Event\Event([
        'context' => 'page',
        'user' => new FakeUser(['grav-caxton.use', 'grav-caxton.source']),
        'fields' => [['name' => 'content', 'type' => 'markdown']],
    ]);
    $customToolbarPlugin->onApiBlueprintResolved($customToolbarBlueprint);
    expectSame(
        ['link', 'separator', 'blockquote', 'bullet_list'],
        $customToolbarBlueprint['fields'][0]['caxton']['toolbar'],
        'Toolbar configuration must preserve safe order, aliases, and separators while dropping unknown items.'
    );

    expectSame(['onCaxtonExtensionRegister'], $container->events, 'The extension event must fire once.');
    expect(isset($container['gravCaxton']), 'The enabled plugin must register gravCaxton.');
    $service = $container['gravCaxton'];
    expect($service instanceof CaxtonServiceInterface, 'The service must implement the public interface.');
    expect($service->extensions()->has('fixture/notice'), 'Event listeners must be able to register extensions.');

    $fixturePath = __DIR__ . '/fixtures/representative.md';
    $source = file_get_contents($fixturePath);
    expect(is_string($source), 'The representative fixture must be readable.');
    $document = $service->parse($source);
    expectSame($source, $service->serialize($document), 'No-edit serialization must be byte-identical.');
    expectSame(hash('sha256', $source), $document->sourceHash, 'The document hash must be deterministic.');
    expectSame($document->sourceHash, $service->parse($source)->sourceHash, 'Repeated parsing must be deterministic.');

    $coverage = 0;
    $types = [];
    foreach ($document->blocks as $block) {
        expectSame($coverage, $block->offset, 'Blocks must provide contiguous source coverage.');
        $coverage += $block->length;
        $types[] = $block->type;
    }
    expectSame(strlen($source), $coverage, 'Blocks must cover every source byte.');
    foreach (['frontmatter', 'heading', 'list', 'blockquote', 'fenced-code', 'media', 'table-or-pipe', 'twig', 'shortcode'] as $type) {
        expect(in_array($type, $types, true), 'Representative fixture must classify ' . $type . '.');
    }

    $heading = null;
    foreach ($document->blocks as $block) {
        if ($block->type === 'heading' && $block->visualEditable) {
            $heading = $block;
            break;
        }
    }
    expect($heading !== null, 'A safe heading must be visually editable.');
    $edit = $service->replaceBlockText($document, $heading->id, 'Edited heading', $document->sourceHash);
    $expected = substr($source, 0, $heading->offset)
        . '# Edited heading' . (str_ends_with($heading->source, "\n") ? "\n" : '')
        . substr($source, $heading->offset + $heading->length);
    expectSame($expected, $edit->document->source, 'Only the selected safe block may change.');
    expectSame($expected, $service->serialize($edit->document), 'Edited documents must serialize deterministically.');

    $plainDocument = $service->parse("Before\r\n\r\nEditable paragraph\r\n\r\nAfter\r\n");
    $paragraph = null;
    foreach ($plainDocument->blocks as $block) {
        if ($block->type === 'paragraph' && ($block->metadata['text'] ?? null) === 'Editable paragraph') {
            $paragraph = $block;
            break;
        }
    }
    expect($paragraph !== null && $paragraph->visualEditable, 'A plain paragraph must be visually editable.');
    $paragraphEdit = $service->replaceBlockText(
        $plainDocument,
        $paragraph->id,
        'Changed paragraph',
        $plainDocument->sourceHash
    );
    expectSame(
        "Before\r\n\r\nChanged paragraph\r\n\r\nAfter\r\n",
        $paragraphEdit->document->source,
        'A paragraph edit must preserve CRLF and every outside byte.'
    );

    $opaque = null;
    foreach ($document->blocks as $block) {
        if ($block->type === 'fenced-code') {
            $opaque = $block;
            break;
        }
    }
    expect($opaque !== null && !$opaque->visualEditable, 'Code fences must remain inert in the foundation.');
    expectThrows(
        BlockNotEditableException::class,
        static fn () => $service->replaceBlockText($document, $opaque->id, 'changed', $document->sourceHash),
        'Opaque blocks must reject visual edits.'
    );
    expectThrows(
        StaleSourceException::class,
        static fn () => $service->replaceBlockText($document, $heading->id, 'changed', str_repeat('0', 64)),
        'Stale hashes must reject edits.'
    );
    expectThrows(
        InvalidSourceException::class,
        static fn () => $service->replaceBlockText($document, $heading->id, "two\nlines", $document->sourceHash),
        'Multiline replacement must remain outside the 0.1.0 safe subset.'
    );

    foreach (["alpha\r\nbeta\r\n", "alpha\rbeta\r", "alpha\nbeta", "no-final-newline", "Unicode 😀\n"] as $variant) {
        $parsed = $service->parse($variant);
        expectSame($variant, $service->serialize($parsed), 'Line-ending and Unicode variants must round trip exactly.');
    }
    $mixed = $service->parse("a\r\nb\nc\r");
    expectSame('mixed', $mixed->newlineStyle, 'Mixed line endings must be reported without normalization.');

    $malformed = file_get_contents(__DIR__ . '/fixtures/malformed.md');
    expect(is_string($malformed), 'The malformed fixture must be readable.');
    $malformedDocument = $service->parse($malformed);
    expectSame($malformed, $service->serialize($malformedDocument), 'Malformed source must remain byte-identical.');
    expectSame('unclosed-fence', $malformedDocument->diagnostics[0]->code ?? null, 'Malformed fences need diagnostics.');

    expectThrows(
        InvalidSourceException::class,
        static fn () => $service->parse("unsafe\0source"),
        'NUL source must fail safely.'
    );
    $smallService = new \Grav\Plugin\GravCaxton\Service\CaxtonService(
        new \Grav\Plugin\GravCaxton\Document\SourceDocumentParser(1024),
        new ExtensionRegistry()
    );
    expectThrows(
        SourceTooLargeException::class,
        static fn () => $smallService->parse(str_repeat('x', 1025)),
        'Oversized source must fail before parsing.'
    );

    $registry = new ExtensionRegistry();
    $registry->register(new FakeExtension('fixture/zeta'));
    $registry->register(new FakeExtension('fixture/alpha'));
    expectSame(
        ['fixture/alpha', 'fixture/zeta'],
        array_map(static fn (CaxtonExtensionInterface $extension): string => $extension->id(), $registry->all()),
        'Extension enumeration must be stable and sorted by ID.'
    );
    expectThrows(
        DuplicateExtensionException::class,
        static fn () => $registry->register(new FakeExtension('fixture/alpha')),
        'Duplicate extension IDs must fail deterministically.'
    );
    expectThrows(
        \InvalidArgumentException::class,
        static fn () => $registry->register(new FakeExtension('not-namespaced')),
        'Extension IDs must be namespaced.'
    );

    $disabledContainer = new ContractContainer();
    $disabled = new \Grav\Plugin\GravCaxtonPlugin();
    $disabled->setContractContext(
        $disabledContainer,
        new ContractConfig(['plugins.grav-caxton.enabled' => false])
    );
    $disabled->onPluginsInitialized();
    expect(!isset($disabledContainer['gravCaxton']), 'Disabled Caxton must leave the service absent.');

    $existingContainer = new ContractContainer();
    $sentinel = new \stdClass();
    $existingContainer['gravCaxton'] = $sentinel;
    $existing = new \Grav\Plugin\GravCaxtonPlugin();
    $existing->setContractContext($existingContainer, new ContractConfig());
    $existing->onPluginsInitialized();
    expectSame($sentinel, $existingContainer['gravCaxton'], 'Caxton must not replace an existing service.');

    $failureContainer = new ContractContainer();
    $failureLogger = new ContractLogger();
    $failureContainer['log'] = $failureLogger;
    $secret = 'do-not-log-this-source-or-secret';
    $failureContainer->eventListener = static function () use ($secret): void {
        throw new RuntimeException($secret);
    };
    $failurePlugin = new \Grav\Plugin\GravCaxtonPlugin();
    $failurePlugin->setContractContext($failureContainer, new ContractConfig());
    $failurePlugin->onPluginsInitialized();
    expect(isset($failureContainer['gravCaxton']), 'Extension failure must not prevent the core service.');
    expect(count($failureLogger->errors) === 1, 'Extension failure must produce one bounded log entry.');
    expect(!str_contains($failureLogger->errors[0], $secret), 'Registration logs must not disclose exception content.');

    fwrite(STDOUT, "Caxton 0.1.0 contract checks passed.\n");
}
