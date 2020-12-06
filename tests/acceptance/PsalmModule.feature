Feature: Psalm module
  In order to test Psalm plugins
  As a Psalm plugin developer
  I need to be able to write tests

  Background:
    Given I have the following code preamble
    """
    <?php

    """
    # Psalm enables cache when there's a composer.lock file
    And I have empty composer.lock

  Scenario: Running with no errors
    Given I have the following code
      """
      atan(1.1);
      """
    When I run Psalm
    Then I see no errors
    And I see exit code 0

  Scenario: Running with errors
    Given I have the following code
      """
      atan("asdfg");
      """
    When I run Psalm
    Then I see these errors
      | Type                  | Message                                            |
      | InvalidScalarArgument | Argument 1 of atan expects float, string% provided |
    And I see no other errors
    And I see exit code 1

  Scenario: Skipping depending on a certain Psalm version
    Given I have Psalm newer than "999.99" (because of "me wanting to see if it skips")
    And I have the following code
      """
      atan(1.9);
      """
    When I run Psalm
    Then I see no errors

  Scenario: Running depending on a certain Psalm version
    Given I have Psalm older than "999.99" (because of "me wanting to see if it runs")
    And I have the following code
      """
      atan("zzzzzzz");
      """
    When I run Psalm
    Then I see these errors
      | Type                     | Message |
      | InvalidScalarArgument    | /./     |
    And I see no other errors

  Scenario: Running Psalm with dead code detection
    Given I have the following code
      """
      class CD {
        /** @return void */
        private function m(int $p) {}
      }
      """
    When I run Psalm with dead code detection
    Then I see these errors
      | Type         | Message                                     |
      | UnusedParam  | Param $p is never referenced in this method |
      | UnusedClass  | Class CD is never used                      |
    And I see no other errors

  Scenario: Running Psalm with custom config
    Given I have the following config
      """
      <?xml version="1.0"?>
      <psalm totallyTyped="true">
        <projectFiles>
          <directory name="."/>
        </projectFiles>
        <issueHandlers>
          <InvalidScalarArgument errorLevel="suppress"/>
        </issueHandlers>
      </psalm>
      """
    And I have the following code
      """
      atan("asdzzz");
      """
    When I run Psalm
    Then I see no errors

  Scenario: Running psalm on an individual file
    Given I have the following code in "C.php"
      """
      <?php
      class C extends PPP {}
      """
    And I have the following code in "P.php"
      """
      <?php
      class PPP {}
      """
    When I run Psalm on "P.php"
    Then I see no errors

  Scenario: Running psalm on an individual file without autoloader
    Given I have the following code in "C.php"
      """
      <?php
      class C extends PPP {}
      """
    And I have the following code in "P.php"
      """
      <?php
      class PPP {}
      """
    When I run Psalm on "C.php"
    Then I see these errors
      | Type           | Message                               |
      | UndefinedClass | Class or interface PPP does not exist |

  Scenario: Running psalm on an individual file with autoloader
    Given I have the following code in "C.php"
      """
      <?php
      class C extends PPP {}
      """
    And I have the following code in "P.php"
      """
      <?php
      class PPP {}
      """
    And I have the following class map
      | Class | File  |
      | C     | C.php |
      | PPP   | P.php |
    When I run Psalm on "C.php"
    Then I see no errors

  Scenario: Running psalm on an individual file with autoloader using namespaces
    Given I have the following code in "C.php"
      """
      <?php
      namespace NS;
      class C extends PPP {}
      """
    And I have the following code in "P.php"
      """
      <?php
      namespace NS;
      class PPP {}
      """
    And I have the following class map
      | Class    | File  |
      | NS\C     | C.php |
      | NS\PPP   | P.php |
    When I run Psalm on "C.php"
    Then I see no errors

  Scenario: Using regexps to match error messages
    Given I have the following code
      """
      class CCC extends PPP {}
      """
    When I run Psalm
    Then I see these errors
      | Type           | Message               |
      | UndefinedClass | /P{3} does not exist/ |
    And I see no other errors

  Scenario: Escaping pipes in regexps
    Given I have the following code
      """
      class CC extends PPP {}
      """
    When I run Psalm
    Then I see these errors
      | Type           | Message                    |
      | UndefinedClass | /(P\|A){3} does not exist/ |
    And I see no other errors

  Scenario: Using backslashes in regexps
    Given I have the following code
      """
      class C extends PPP {}
      """
    When I run Psalm
    Then I see these errors
      | Type           | Message                   |
      | UndefinedClass | /\bP{3}\b does not exist/ |
    And I see no other errors

  Scenario: Psalm crashes (3.7.x)
    Given I have Psalm older than "3.8.0" (because of "exit code changed in 3.8.0")
    And I have the following code in "autoload.php"
      """
      <?php missing_function();
      """
    And I have the following config
      """
      <?xml version="1.0"?>
      <psalm totallyTyped="true" autoloader="autoload.php">
        <projectFiles>
          <directory name="."/>
        </projectFiles>
      </psalm>
      """
    When I run Psalm
    Then I see exit code 255

  Scenario: Psalm crashes (3.8.0+)
    Given I have Psalm newer than "3.7.2" (because of "exit code changed in 3.8.0")
    And I have the following code in "autoload.php"
      """
      <?php missing_function_2();
      """
    And I have the following config
      """
      <?xml version="1.0"?>
      <psalm totallyTyped="true" autoloader="autoload.php">
        <projectFiles>
          <directory name="."/>
        </projectFiles>
      </psalm>
      """
    When I run Psalm
    Then I see exit code 1

  Scenario: Running with taint analysis
    Given I have Psalm with taint analysis
    And I have the following code
      """
      <?php echo $_GET['param'];
      """
    When I run Psalm with taint analysis
    Then I see these errors
      | Type        | Message |
      | /Tainted.*/ | /./     |
    And I see no other errors

  Scenario: Skipping when dependency is not satisfied
    Given I have the "codeception/module-cli" package satisfying the "^123.0"
    And I have the following code
      """
      atan("zzzz");
      """
    When I run Psalm
    Then I see no errors

  Scenario: Skipping when dependency is unknown
    Given I have the "mr-nobody/unknown-package" package satisfying the "^123.0"
    And I have the following code
      """
      atan("zzz");
      """
    When I run Psalm
    Then I see no errors

  Scenario: Running when dependency is satisfied
    Given I have the "codeception/module-cli" package satisfying the "*"
    And I have the following code
      """
      atan("zz");
      """
    When I run Psalm
    Then I see these errors
      | Type                     | Message |
      | InvalidScalarArgument    | /./     |
    And I see no other errors
