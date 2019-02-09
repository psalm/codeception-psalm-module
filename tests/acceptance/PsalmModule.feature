Feature: Psalm module
  In order to test Psalm plugins
  As a Psalm plugin developer
  I need to be able to write tests

  Background:
    Given I have the following code preamble
    """
    <?php

    """

  Scenario: Running with no errors
    Given I have the following code
      """
      atan(1.1);
      """
    When I run Psalm
    Then I see no errors

  Scenario: Running with errors
    Given I have the following code
      """
      atan("asd");
      """
    When I run Psalm
    Then I see these errors
      | Type                  | Message                                            |
      | InvalidScalarArgument | Argument 1 of atan expects float, string% provided |
    And I see no other errors

  Scenario: Skipping depending on a certain Psalm version
    Given I have Psalm newer than "999.99" (because of "me wanting to see if it skips")
    And I have the following code
      """
      atan(1.);
      """
    When I run Psalm
    Then I see no errors

  Scenario: Running depending on a certain Psalm version
    Given I have Psalm older than "999.99" (because of "me wanting to see if it runs")
    And I have the following code
      """
      atan(1.);
      """
    When I run Psalm
    Then I see no errors
