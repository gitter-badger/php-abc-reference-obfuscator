<?php
//----------------------------------------------------------------------------------------------------------------------
namespace SetBased\Abc\Obfuscator;

use SetBased\Stratum\Exception\RuntimeException;
use SetBased\Stratum\MySql\StaticDataLayer;
use SetBased\Stratum\Util;

//----------------------------------------------------------------------------------------------------------------------
/**
 * Class for creating PHP variable based on column widths, and auto increment columns and labels.
 */
class ReferenceObfuscatorAutomator
{
  //--------------------------------------------------------------------------------------------------------------------.
  /**
   * All config file as array.
   *
   * @var array
   */
  private $myConfig;

  /**
   * Config file name.
   *
   * @var string
   */
  private $myConfigFileName;

  /**
   * All IDs.
   *
   * @var array
   */
  private $myDatabaseIds;

  /**
   * Array of types with length
   *
   * @var array
   */
  private $myTypes = ['tinyint'   => 1,
                      'smallint'  => 2,
                      'mediumint' => 3,
                      'int'       => 4,
                      'bigint'    => 8];

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Main function for read,create and write constants.
   *
   * @param string $theConfigFilename
   *
   * @return int
   */
  public function main($theConfigFilename)
  {
    $this->myConfigFileName = $theConfigFilename;

    $this->readConfigFile($theConfigFilename);

    $this->getDatabseIDs();

    $this->generateConstants();

    $this->writeConstant();

    return 0;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Reads configuration parameters from the configuration file.
   *
   * @param string $theConfigFilename
   *
   * @throws \SetBased\Abc\Error\RuntimeException
   */
  protected function readConfigFile($theConfigFilename)
  {
    $content = file_get_contents($theConfigFilename);
    if ($content===false)
    {
      throw new \SetBased\Abc\Error\RuntimeException("Unable to read file '%s'.", $theConfigFilename);
    }
    $this->myConfig = json_decode($content, true);
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Searches for 3 lines in the source code of the class for constants. The lines are:
   * * The first line of the doc block with the annotation '@setbased.abc.obfuscator'.
   * * The last line of this doc block.
   * * The last line of continuous constant declarations directly after the doc block.
   * If one of these line can not be found the line number will be set to null.
   *
   * @param string $theSourceCode The source code of the constant class.
   *
   * @return array With the 3 line number as described
   */
  private function extractLines($theSourceCode)
  {
    $tokens = token_get_all($theSourceCode);

    $line1 = null;
    $line2 = null;
    $line3 = null;

    // Find annotation @setbased.abc.obfuscator
    $step = 1;
    foreach ($tokens as $key => $token)
    {
      switch ($step)
      {
        case 1:
          // Step 1: Find doc comment with annotation.
          if (is_array($token) && $token[0]==T_DOC_COMMENT)
          {
            if (strpos($token[1], '@setbased.abc.obfuscator')!==false)
            {
              $line1 = $token[2];
              $step  = 2;
            }
          }
          break;

        case 2:
          // Step 2: Find end of doc block.
          if (is_array($token))
          {
            if ($token[0]==T_WHITESPACE)
            {
              $line2 = $token[2];
              if (substr_count($token[1], "\n")<=1)
              {
                // Ignore whitespace.
              }
            }
            else
            {
              $step = 3;
            }
          }
          break;

        case 3:
          // Step 4: Find en of constants declarations.
          if (is_string($token))
          {
            if ($token==']' && $tokens[$key + 1]==';')
            {
              if ($tokens[$key + 2][0]==T_WHITESPACE)
              {
                $line3 = $tokens[$key + 2][2];
                $step  = 4;
              }
            }
          }
          break;

        case 4:
          // Leave loop.
          break;
      }
    }

    // @todo get indent based on indent of the doc block.

    return [$line1, $line2, $line3];
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Create constants for each database id.
   */
  private function generateConstants()
  {
    foreach ($this->myDatabaseIds as $row)
    {
      if (!in_array($row["table_name"], $this->myConfig['ignore']))
      {
        $length                           = $this->myTypes[$row['data_type']];
        $key                              = rand(1, pow(2, 16) - 1);
        $mask                             = rand(pow(2, 8 * $length - 1), pow(2, 8 * $length) - 1);
        $name                             = substr($row["column_name"], 0, strlen($row["column_name"]) - 3);
        $this->myConfig['constants'][$name] = [$length, $key, $mask];
      }
    }
    $this->rewriteConfig();
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Load database IDs .
   */
  private function getDatabseIDs()
  {
    $database = $this->myConfig['database'];
    $query    = "
select table_name
,      column_name
,      data_type
from       information_schema.columns
where table_schema = database()
and   extra        = 'auto_increment'
order by table_name";
    StaticDataLayer::connect($database['host_name'], $database['user_name'], $database['password'], $database['database_name']);
    $this->myDatabaseIds = StaticDataLayer::executeRows($query);
    StaticDataLayer::disconnect();
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Create lines for each database id from constans.
   *
   * @return array
   */
  private function makeVariableStatements()
  {
    $variable = "[\n";
    foreach ($this->myConfig['constants'] as $key => $value)
    {
      $variable .= sprintf("  \"%s\" => [%s, %s, %s],\n", $key, $value[0], $value[1], $value[2]);
    }
    $variable .= ']';
    $constants[] = sprintf("%s = %s;", $this->myConfig['variable'], $variable, true);

    return $constants;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Write new data to config file.
   */
  private function rewriteConfig()
  {
    Util::writeTwoPhases($this->myConfigFileName, json_encode($this->myConfig));
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Insert new and replace old (if any) constant declaration statements in a PHP source file.
   */
  private function writeConstant()
  {
    $source = file_get_contents($this->myConfig['file']);
    if ($source===false)
    {
      throw new RuntimeException("Unable the open source file '%s'.", $this->myConfig['file']);
    }
    $source_lines = explode("\n", $source);

    // Search for the lines where to insert and replace constant declaration statements.
    $line_numbers = $this->extractLines($source);
    if (!isset($line_numbers[0]))
    {
      throw new RuntimeException("Annotation not found in '%s'.", $this->myConfig['file']);
    }

    // Generate the variable statements.
    $constants = $this->makeVariableStatements();

    // Insert new and replace old (if any) constant declaration statements.
    $tmp1 = array_splice($source_lines, 0, $line_numbers[1]);
    $tmp2 = array_splice($source_lines, (isset($line_numbers[2])) ? $line_numbers[2] - $line_numbers[1] : 0);
    $source_lines = array_merge($tmp1, $constants, $tmp2);

    // Save the file.
    Util::writeTwoPhases($this->myConfig['file'], implode("\n", $source_lines));
  }

  //--------------------------------------------------------------------------------------------------------------------
}

//----------------------------------------------------------------------------------------------------------------------
