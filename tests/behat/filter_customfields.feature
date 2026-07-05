@qbank_customfieldfilter @core_question @core_customfield
Feature: Filter questions by custom field values
  In order to find questions with specific custom field values
  As a teacher
  I want to filter the question bank by the combined custom fields filter

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | 1        | teacher1@example.com |
    And the following "courses" exist:
      | fullname | shortname | format |
      | Course 1 | C1        | weeks  |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
    And the following "activities" exist:
      | activity | name    | intro            | course | idnumber |
      | qbank    | Qbank 1 | Question bank 1  | C1     | qbank1   |
    And the following "question categories" exist:
      | contextlevel    | reference | name           |
      | Activity module | qbank1    | Test questions |
    And the following "custom field categories" exist:
      | name              | component          | area     | itemid |
      | Category for test | qbank_customfields | question | 0      |
    And the following "custom fields" exist:
      | name       | category          | type   | shortname  | configdata                                                           |
      | Bloom      | Category for test | select | bloom      | {"options":"Remember\nUnderstand\nApply\nAnalyze\nEvaluate\nCreate"} |
      | Difficulty | Category for test | select | difficulty | {"options":"Easy\nHard"}                                             |
    And the following "questions" exist:
      | questioncategory | qtype | name            | user     | questiontext    |
      | Test questions   | essay | understand only | teacher1 | Question 1 text |
      | Test questions   | essay | apply only      | teacher1 | Question 2 text |
      | Test questions   | essay | understand hard | teacher1 | Question 3 text |
      | Test questions   | essay | create easy     | teacher1 | Question 4 text |
      | Test questions   | essay | no custom field | teacher1 | Question 5 text |
    And I am on the "understand only" "core_question > edit" page logged in as "teacher1"
    And I change window size to "large"
    And I expand all fieldsets
    And I set the following fields to these values:
      | Bloom | Understand |
    And I press "id_submitbutton"
    And I am on the "apply only" "core_question > edit" page
    And I expand all fieldsets
    And I set the following fields to these values:
      | Bloom | Apply |
    And I press "id_submitbutton"
    And I am on the "understand hard" "core_question > edit" page
    And I expand all fieldsets
    And I set the following fields to these values:
      | Bloom      | Understand |
      | Difficulty | Hard       |
    And I press "id_submitbutton"
    And I am on the "create easy" "core_question > edit" page
    And I expand all fieldsets
    And I set the following fields to these values:
      | Bloom      | Create |
      | Difficulty | Easy   |
    And I press "id_submitbutton"
    # "no custom field" is left untouched: no {customfield_data} row exists for it at all.
    And I am on the "Qbank 1" "core_question > question bank" page

  @javascript
  Scenario: A single custom field value filters to matching questions
    When I apply question bank filter "Custom fields" with value "Bloom: Understand"
    Then I should see "understand only" in the "categoryquestions" "table"
    And I should see "understand hard" in the "categoryquestions" "table"
    And I should not see "apply only" in the "categoryquestions" "table"
    And I should not see "create easy" in the "categoryquestions" "table"
    And I should not see "no custom field" in the "categoryquestions" "table"

  @javascript
  Scenario: Multiple values on the same field are combined with OR
    When I apply question bank filter "Custom fields" with value "Bloom: Understand,Bloom: Apply"
    Then I should see "understand only" in the "categoryquestions" "table"
    And I should see "apply only" in the "categoryquestions" "table"
    And I should see "understand hard" in the "categoryquestions" "table"
    And I should not see "create easy" in the "categoryquestions" "table"
    And I should not see "no custom field" in the "categoryquestions" "table"

  @javascript
  Scenario: Values from different fields default to matching all of them
    When I apply question bank filter "Custom fields" with value "Bloom: Understand,Difficulty: Hard"
    Then I should see "understand hard" in the "categoryquestions" "table"
    And I should not see "understand only" in the "categoryquestions" "table"
    And I should not see "apply only" in the "categoryquestions" "table"
    And I should not see "create easy" in the "categoryquestions" "table"
    And I should not see "no custom field" in the "categoryquestions" "table"

  @javascript
  Scenario: Switching the join type to Any matches questions with any of the selected fields
    Given I apply question bank filter "Custom fields" with value "Bloom: Understand,Difficulty: Hard"
    When I set the field "Match" in the "Filter 2" "fieldset" to "Any"
    And I click on "Apply filters" "button"
    Then I should see "understand only" in the "categoryquestions" "table"
    And I should see "understand hard" in the "categoryquestions" "table"
    And I should not see "apply only" in the "categoryquestions" "table"
    And I should not see "create easy" in the "categoryquestions" "table"
    And I should not see "no custom field" in the "categoryquestions" "table"

  @javascript
  Scenario: Switching the join type to None matches questions with none of the selected fields
    Given I apply question bank filter "Custom fields" with value "Bloom: Understand,Difficulty: Hard"
    When I set the field "Match" in the "Filter 2" "fieldset" to "None"
    And I click on "Apply filters" "button"
    Then I should see "apply only" in the "categoryquestions" "table"
    And I should see "create easy" in the "categoryquestions" "table"
    And I should see "no custom field" in the "categoryquestions" "table"
    And I should not see "understand only" in the "categoryquestions" "table"
    And I should not see "understand hard" in the "categoryquestions" "table"
