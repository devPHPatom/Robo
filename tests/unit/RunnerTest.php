<?php
use Robo\Robo;
use Symfony\Component\Console\Output\BufferedOutput;

class RunnerTest extends \Codeception\TestCase\Test
{
    /**
     * @var \Robo\Runner
     */
    private $runner;

    /**
     * @var \CodeGuy
     */
    protected $guy;

    public function _before()
    {
        $this->runner = new \Robo\Runner('\Robo\RoboFileFixture');
    }

    public function testHandleError()
    {
        $tmpLevel = error_reporting();

        $this->assertFalse($this->runner->handleError());
        error_reporting(0);
        $this->assertTrue($this->runner->handleError());

        error_reporting($tmpLevel);
    }

    public function testErrorIsHandled()
    {
        $tmpLevel = error_reporting();

        // Set error_get_last to a known state.  Note that it can never be
        // reset; see http://php.net/manual/en/function.error-get-last.php
        @trigger_error('control');
        $error_description = error_get_last();
        $this->assertEquals('control', $error_description['message']);
        @trigger_error('');
        $error_description = error_get_last();
        $this->assertEquals('', $error_description['message']);

        // Set error_reporting to a non-zero value.  In this instance,
        // 'trigger_error' would abort our test script, so we use
        // @trigger_error so that execution will continue.  With our
        // error handler in place, the value of error_get_last() does
        // not change.
        error_reporting(E_USER_ERROR);
        set_error_handler(array($this->runner, 'handleError'));
        @trigger_error('test error', E_USER_ERROR);
        $error_description = error_get_last();
        $this->assertEquals('', $error_description['message']);

        // Set error_reporting to zero.  Now, even 'trigger_error'
        // does not abort execution.  The value of error_get_last()
        // still does not change.
        error_reporting(0);
        trigger_error('test error 2', E_USER_ERROR);
        $error_description = error_get_last();
        $this->assertEquals('', $error_description['message']);

        error_reporting($tmpLevel);
    }

    public function testThrowsExceptionWhenNoContainerAvailable()
    {
        \PHPUnit_Framework_TestCase::setExpectedExceptionRegExp(
            '\RuntimeException',
            '/container is not initialized yet.*/'
        );
        Robo::unsetContainer();
        Robo::getContainer();
    }

    public function testRunnerNoSuchCommand()
    {
        $argv = ['placeholder', 'no-such-command'];
        $this->runner->execute($argv, null, null, $this->guy->capturedOutputStream());
        $this->guy->seeInOutput('Command "no-such-command" is not defined.');
    }

    public function testRunnerList()
    {
        $argv = ['placeholder', 'list'];
        $this->runner->execute($argv, null, null, $this->guy->capturedOutputStream());
        $this->guy->seeInOutput('test:array-args');
    }

    public function testRunnerTryArgs()
    {
        $argv = ['placeholder', 'test:array-args', 'a', 'b', 'c'];
        $this->runner->execute($argv, null, null, $this->guy->capturedOutputStream());

        $expected = <<<EOT
>  The parameters passed are:
array (
  0 => 'a',
  1 => 'b',
  2 => 'c',
)

EOT;
        $this->guy->seeOutputEquals($expected);
    }

    public function testSymfonyStyle()
    {
        $argv = ['placeholder', 'test:symfony-style'];
        $this->runner->execute($argv, null, null, $this->guy->capturedOutputStream());
        $this->guy->seeInOutput('Some text in section one.');
    }

    public function testCommandEventHook()
    {
        $argv = ['placeholder', 'test:command-event'];
        $this->runner->execute($argv, null, null, $this->guy->capturedOutputStream());

        $expected = <<<EOT
 This is the command-event hook for the test:command-event command.
 This is the main method for the test:command-event command.
 This is the post-command hook for the test:command-event command.
EOT;
        $this->guy->seeInOutput($expected);
    }

    public function testCustomEventHook()
    {
        $argv = ['placeholder', 'test:custom-event'];
        $this->runner->execute($argv, null, null, $this->guy->capturedOutputStream());

        $expected = 'one,two';
        $this->guy->seeInOutput($expected);
    }

    public function testRoboStaticRunMethod()
    {
        $argv = ['placeholder', 'test:symfony-style'];
        $commandFiles = ['\Robo\RoboFileFixture'];
        Robo::run($argv, $commandFiles, 'MyApp', '1.2.3', $this->guy->capturedOutputStream());
        $this->guy->seeInOutput('Some text in section one.');
    }

    public function testDeploy()
    {
        $argv = ['placeholder', 'test:deploy', '--simulate'];
        $this->runner->execute($argv, null, null, $this->guy->capturedOutputStream());
        $this->guy->seeInOutput('[Simulator] Simulating Remote\\Ssh(\'mysite.com\', null)');
        $this->guy->seeInOutput('[Simulator] Running ssh mysite.com \'cd "/var/www/somesite" && git pull\'');
    }

    public function testRunnerTryError()
    {
        $argv = ['placeholder', 'test:error'];
        $result = $this->runner->execute($argv, null, null, $this->guy->capturedOutputStream());

        $this->guy->seeInOutput('[Exec] Running ls xyzzy');
        $this->assertTrue($result > 0);
    }

    public function testRunnerTrySimulatedError()
    {
        $argv = ['placeholder', 'test:error', '--simulate'];
        $result = $this->runner->execute($argv, null, null, $this->guy->capturedOutputStream());

        $this->guy->seeInOutput('Simulating Exec');
        $this->assertEquals(0, $result);
    }

    public function testRunnerTryException()
    {
        $argv = ['placeholder', 'test:exception', '--task'];
        $result = $this->runner->execute($argv, null, null, $this->guy->capturedOutputStream());

        $this->guy->seeInOutput('Task failed with an exception');
        $this->assertEquals(1, $result);
    }

    public function testInitCommand()
    {
        $container = \Robo\Robo::getContainer();
        $app = $container->get('application');
        $app->addInitRoboFileCommand(getcwd() . '/testRoboFile.php', 'RoboTestClass');

        $argv = ['placeholder', 'init'];
        $status = $this->runner->run($argv, $this->guy->capturedOutputStream(), $app);
        $this->guy->seeInOutput('testRoboFile.php will be created in the current directory');
        $this->assertEquals(0, $status);

        $this->assertTrue(file_exists('testRoboFile.php'));
        $commandContents = file_get_contents('testRoboFile.php');
        unlink('testRoboFile.php');
        $this->assertContains('class RoboTestClass', $commandContents);
    }

    public function testTasksStopOnFail()
    {
        $argv = ['placeholder', 'test:stop-on-fail'];
        $result = $this->runner->execute($argv, null, null, $this->guy->capturedOutputStream());

        $this->guy->seeInOutput('[');
        $this->assertTrue($result > 0);
    }

    public function testInvalidRoboDirectory()
    {
        $runnerWithNoRoboFile = new \Robo\Runner();

        $argv = ['placeholder', 'list', '-f', 'no-such-directory'];
        $result = $runnerWithNoRoboFile->execute($argv, null, null, $this->guy->capturedOutputStream());

        $this->guy->seeInOutput('Path `no-such-directory` is invalid; please provide a valid absolute path to the Robofile to load.');
    }

    public function testUnloadableRoboFile()
    {
        $runnerWithNoRoboFile = new \Robo\Runner();

        $argv = ['placeholder', 'list', '-f', dirname(__DIR__) . '/src/RoboFileFixture.php'];
        $result = $runnerWithNoRoboFile->execute($argv, null, null, $this->guy->capturedOutputStream());

        // We cannot load RoboFileFixture.php via -f / --load-from because
        // it has a namespace, and --load-from does not support that.
        $this->guy->seeInOutput('Class RoboFileFixture was not loaded');
    }

    public function testRunnerQuietOutput()
    {
        $argv = ['placeholder', 'test:verbosity', '--quiet'];
        $result = $this->runner->execute($argv, null, null, $this->guy->capturedOutputStream());

        $this->guy->doNotSeeInOutput('This command will print more information at higher verbosity levels');
        $this->guy->doNotSeeInOutput('This is a verbose message (-v).');
        $this->guy->doNotSeeInOutput('This is a very verbose message (-vv).');
        $this->guy->doNotSeeInOutput('This is a debug message (-vvv).');
        $this->guy->doNotSeeInOutput(' [warning] This is a warning log message.');
        $this->guy->doNotSeeInOutput(' [notice] This is a notice log message.');
        $this->guy->doNotSeeInOutput(' [debug] This is a debug log message.');
        $this->assertEquals(0, $result);
    }

    public function testRunnerVerboseOutput()
    {
        $argv = ['placeholder', 'test:verbosity', '-v'];
        $result = $this->runner->execute($argv, null, null, $this->guy->capturedOutputStream());

        $this->guy->seeInOutput('This command will print more information at higher verbosity levels');
        $this->guy->seeInOutput('This is a verbose message (-v).');
        $this->guy->doNotSeeInOutput('This is a very verbose message (-vv).');
        $this->guy->doNotSeeInOutput('This is a debug message (-vvv).');
        $this->guy->seeInOutput(' [warning] This is a warning log message.');
        $this->guy->seeInOutput(' [notice] This is a notice log message.');
        $this->guy->doNotSeeInOutput(' [debug] This is a debug log message.');
        $this->assertEquals(0, $result);
    }

    public function testRunnerVeryVerboseOutput()
    {
        $argv = ['placeholder', 'test:verbosity', '-vv'];
        $result = $this->runner->execute($argv, null, null, $this->guy->capturedOutputStream());

        $this->guy->seeInOutput('This command will print more information at higher verbosity levels');
        $this->guy->seeInOutput('This is a verbose message (-v).');
        $this->guy->seeInOutput('This is a very verbose message (-vv).');
        $this->guy->doNotSeeInOutput('This is a debug message (-vvv).');
        $this->guy->seeInOutput(' [warning] This is a warning log message.');
        $this->guy->seeInOutput(' [notice] This is a notice log message.');
        $this->guy->doNotSeeInOutput(' [debug] This is a debug log message.');
        $this->assertEquals(0, $result);
    }

    public function testRunnerDebugOutput()
    {
        $argv = ['placeholder', 'test:verbosity', '-vvv'];
        $result = $this->runner->execute($argv, null, null, $this->guy->capturedOutputStream());

        $this->guy->seeInOutput('This command will print more information at higher verbosity levels');
        $this->guy->seeInOutput('This is a verbose message (-v).');
        $this->guy->seeInOutput('This is a very verbose message (-vv).');
        $this->guy->seeInOutput('This is a debug message (-vvv).');
        $this->guy->seeInOutput(' [warning] This is a warning log message.');
        $this->guy->seeInOutput(' [notice] This is a notice log message.');
        $this->guy->seeInOutput(' [debug] This is a debug log message.');
        $this->assertEquals(0, $result);
    }
}
