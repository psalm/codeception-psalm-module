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
    const VERSION_OPERATORS = [
        'newer than' => '>',
        'older than' => '<',
    ];

    const DEFAULT_PSALM_CONFIG = "<?xml version=\"1.0\"?>\n"
        . "<psalm totallyTyped=\"true\">\n"
        . "  <projectFiles>\n"
        . "    <directory name=\".\"/>\n"
        . "  </projectFiles>\n"
        . "</psalm>\n";

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
    private $psalmConfig = '';

    /** @var string */
    private $preamble = '';

    /** @var array<int, array{type:string,message:string}> */
    public $errors = [];


    /**
     * @return void
     */
    public function _beforeSuite($configuration = [])
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

    /**
     * @return void
     */
    public function _before(TestInterface $test)
    {
        $this->errors = [];
        $this->config['psalm_path'] = realpath($this->config['psalm_path']);
        $this->psalmConfig = '';
    }

    /**
     * @param string[] $options
     * @return void
     */
    public function runPsalmOn(string $filename, array $options = [])
    {
        $options = array_map('escapeshellarg', $options);
        $cmd = $this->config['psalm_path']
                . ' --output-format=json '
                . join(' ', $options) . ' '
                . ($filename ? escapeshellarg($filename) : '');
        $this->debug('Running: ' . $cmd);
        $this->cli()->runShellCommand($cmd, false);
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
            array_values((array)$errors)
        );
        $this->debug($this->remainingErrors());
    }

    /**
     * @param string[] $options
     * @return void
     */
    public function runPsalmIn(string $dir, array $options = [])
    {
        $pwd = getcwd();
        $this->fs()->amInPath($dir);

        $config = $this->psalmConfig ?: self::DEFAULT_PSALM_CONFIG;
        $this->fs()->writeToFile('psalm.xml', $config);

        $this->runPsalmOn('', $options);
        $this->fs()->amInPath($pwd);
    }

    /**
     * @return void
     */
    public function seeThisError(string $type, string $message)
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
     *
     * @return void
     */
    public function seeNoErrors()
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
     *
     * @return void
     */
    public function haveTheFollowingCodePreamble(string $code)
    {
        $this->preamble = $code;
    }

    /**
     * @When I run psalm
     * @When I run Psalm
     *
     * @return void
     */
    public function runPsalm()
    {
        $this->runPsalmIn(dirname($this->config['default_file']));
    }

    /**
     * @When I run Psalm with dead code detection
     *
     * @return void
     */
    public function runPsalmWithDeadCodeDetection()
    {
        $this->runPsalmIn(dirname($this->config['default_file']), ['--find-dead-code']);
    }


    /**
     * @Given I have the following config :config
     * @return void
     */
    public function haveTheFollowingConfig(string $config)
    {
        $this->psalmConfig = $config;
    }

    /**
     * @Given I have the following code :code
     *
     * @return void
     */
    public function haveTheFollowingCode(string $code)
    {
        $this->fs()->writeToFile(
            $this->config['default_file'],
            $this->preamble . $code
        );
    }

    /**
     * @Given I have some future Psalm that supports this feature :ref
     *
     * @return void
     */
    public function haveSomeFuturePsalmThatSupportsThisFeature(string $ref)
    {
        throw new Skip("Future functionality that Psalm has yet to support: $ref");
    }

    /**
     * @Given /I have Psalm (newer than|older than) "([0-9.]+)" \(because of "([^"]+)"\)/
     *
     * @return void
     */
    public function havePsalmOfACertainVersionRangeBecauseOf(string $operator, string $version, string $reason)
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
     *
     * @return void
     */
    public function seeTheseErrors(TableNode $list)
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
