<?php declare(strict_types=1);
namespace Weirdan\Codeception\Psalm;

use Codeception\Exception\ModuleRequireException;
use Codeception\Exception\Skip;
use Codeception\Exception\TestRuntimeException;
use Codeception\Module as BaseModule;
use Codeception\Module\Cli;
use Codeception\Module\Filesystem;
use Codeception\TestInterface;
use Composer\Semver\Comparator;
use Composer\Semver\VersionParser;
use Muglug\PackageVersions\Versions;
use PHPUnit\Framework\Assert;
use Behat\Gherkin\Node\TableNode;
use RuntimeException;

class Module extends BaseModule
{
    /** @var array<string,string */
    private const VERSION_OPERATORS = [
        'newer than' => '>',
        'older than' => '<',
    ];

    /**
     * @var ?Cli
     */
    private $cli;

    /**
     * @var ?Filesystem
     */
    private $fs;

    /** @var array<string,string> */
    protected $config = [
        'psalm_path' => 'vendor/bin/psalm',
        'default_file' => 'tests/_run/somefile.php',
    ];

    /** @var string */
    private $preamble = '';

    /** @var array{type:string,message:string}[] */
    public $errors = [];

    public function _beforeSuite($configuration = []): void
    {
        $defaultDir = dirname($this->config['default_file']);
        if (file_exists($defaultDir)) {
            if (is_dir($defaultDir)) {
                return;
            }
            unlink($defaultDir);
        }

        if (!mkdir($defaultDir, 0755, true)) {
            throw new TestRuntimeException('Failed to create dir: ' . $defaultDir);
        }
    }
    public function _before(TestInterface $test): void
    {
        $this->errors = [];
    }

    public function runPsalmOn(string $filename): void
    {
        $this->cli()->runShellCommand(
            $this->config['psalm_path']
                . ' --output-format=json '
                . escapeshellarg($filename),
            false
        );
        /**
         * @psalm-suppress MixedAssignment
         * @psalm-suppress MissingPropertyType shouldn't be really happening
         */
        $errors = json_decode((string)$this->cli()->output, true) ?? [];

        $this->errors = array_map(
            function (array $row): array {
                return [
                    'type' => (string) $row['type'],
                    'message' => (string) $row['message'],
                ];
            },
            (array)$errors
        );
        $this->debug($this->remainingErrors());
    }

    public function seeThisError(string $type, string $message): void
    {
        if (empty($this->errors)) {
            Assert::fail("No errors");
        }

        foreach ($this->errors as $i => $error) {
            if ($error['type'] === $type && preg_match($this->convertToRegexp($message), $error['message'])) {
                unset($this->errors[$i]);
                return;
            }
        }

        Assert::fail("Didn't see [ $type $message ] in: \n" . $this->remainingErrors());
    }

    /**
     * @Then I see no errors
     * @Then I see no other errors
     */
    public function seeNoErrors(): void
    {
        if (!empty($this->errors)) {
            Assert::fail("There were errors: \n" . $this->remainingErrors());
        }
    }

    public function seePsalmVersionIs(string $operator, string $version): bool
    {
        $currentVersion = (string) Versions::getShortVersion('vimeo/psalm');
        $this->debug(sprintf("Current version: %s", $currentVersion));

        // todo: move to init/construct/before?
        $parser = new VersionParser();

        $currentVersion =  $parser->normalize($currentVersion);
        $version = $parser->normalize($version);

        $result = Comparator::compare($currentVersion, $operator, $version);
        $this->debug("Comparing $currentVersion $operator $version => $result");

        return $result;
    }

    /**
     * @Given I have the following code preamble :code
     */
    public function haveTheFollowingCodePreamble(string $code): void
    {
        $this->preamble = $code;
    }

    /**
     * @When /I run (?:P|p)salm/
     */
    public function runPsalm(): void
    {
        $this->runPsalmOn($this->config['default_file']);
    }

    /**
     * @Given I have the following code :code
     */
    public function haveTheFollowingCode(string $code): void
    {
        $this->fs()->writeToFile(
            $this->config['default_file'],
            $this->preamble . $code
        );
    }

    /**
     * @Given I have some future Psalm that supports this feature :ref
     */
    public function haveSomeFuturePsalmThatSupportsThisFeature(string $ref): void
    {
        throw new Skip("Future functionality that Psalm has yet to support: $ref");
    }

    /**
     * @Given /I have Psalm (newer than|older than) "([0-9.]+)" \(because of "([^"]+)"\)/
     */
    public function havePsalmOfACertainVersionRangeBecauseOf(string $operator, string $version, string $reason): void
    {
        if (!isset(self::VERSION_OPERATORS[$operator])) {
            throw new TestRuntimeException("Unknown operator: $operator");
        }

        $op = (string) self::VERSION_OPERATORS[$operator];

        if (!$this->seePsalmVersionIs($op, $version)) {
            throw new Skip("This scenario requires Psalm $op $version because of $reason");
        }
    }

    /**
     * @Then I see these errors
     */
    public function seeTheseErrors(TableNode $list): void
    {
        /** @psalm-suppress MixedAssignment */
        foreach (array_values($list->getRows()) as $i => $error) {
            assert(is_array($error));
            if (0 === $i) {
                continue;
            }
            $this->seeThisError((string) $error[0], (string) $error[1]);
        }
    }





    private function convertToRegexp(string $in): string
    {
        return '@' . str_replace('%', '.*', preg_quote($in, '@')) . '@';
    }

    private function cli(): Cli
    {
        if (null === $this->cli) {
            $cli = $this->getModule('Cli');
            if (!$cli instanceof Cli) {
                throw new ModuleRequireException($this, 'Needs Cli module');
            }
            $this->cli = $cli;
        }
        return $this->cli;
    }

    private function fs(): Filesystem
    {
        if (null === $this->fs) {
            $fs = $this->getModule('Filesystem');
            if (!$fs instanceof Filesystem) {
                throw new ModuleRequireException($this, 'Needs Filesystem module');
            }
            $this->fs = $fs;
        }
        return $this->fs;
    }

    private function remainingErrors(): string
    {
        return (string) new TableNode(array_map(
            function (array $error): array {
                return [
                    'type' => $error['type'] ?? '',
                    'message' => $error['message'] ?? '',
                ];
            },
            $this->errors
        ));
    }
}
