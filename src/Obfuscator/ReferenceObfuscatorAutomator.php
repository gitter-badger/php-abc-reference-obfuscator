<?php
//----------------------------------------------------------------------------------------------------------------------
namespace SetBased\Abc\Obfuscator;

use SetBased\Abc\Error\RuntimeException;
use SetBased\Stratum\MySql\StaticDataLayer;
use SetBased\Stratum\Util;

//----------------------------------------------------------------------------------------------------------------------
/**
 * Class for creating parameters for reference obfuscator.
 */
class ReferenceObfuscatorAutomator implements ReferenceObfuscatorMangler
{
  //--------------------------------------------------------------------------------------------------------------------.
  /**
   * The configuration parameters.
   *
   * @var array
   */
  private $myConfig;

  /**
   * The fielname of the configuration filename.
   *
   * @var string
   */
  private $myConfigFileName;

  /**
   * Metadata of all tables with auto increment columns.
   *
   * @var array[]
   */
  private $myTables;

  /**
   * Number of bytes of MySQL integer types.
   *
   * @var array
   */
  private static $myIntegerTypeSizes = ['tinyint'   => 1,
                                        'smallint'  => 2,
                                        'mediumint' => 3,
                                        'int'       => 4,
                                        'bigint'    => 8];

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Main function for read, create and write constants.
   *
   * @param string $theConfigFilename The name of the configuration file.
   *
   * @return int
   */
  public function main($theConfigFilename)
  {
    $this->myConfigFileName = $theConfigFilename;

    $this->readConfigFile($theConfigFilename);

    $this->getDatabaseIds();

    $this->generateConstants();

    $this->writeConstant();

    return 0;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Reads configuration parameters from the configuration file.
   *
   * @param string $theConfigFilename The name of the configuration file.
   *
   * @throws RuntimeException
   */
  protected function readConfigFile($theConfigFilename)
  {
    $content = file_get_contents($theConfigFilename);
    if ($content===false)
    {
      throw new RuntimeException("Unable to read file '%s'.", $theConfigFilename);
    }
    $this->myConfig = json_decode($content, true);
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Searches for 3 lines in the source code with reference obfuscator parameters. The lines are:
   * * The first line of the doc block with the annotation '@setbased.abc.obfuscator'.
   * * The last line of this doc block.
   * * The last line of array declarations directly after the doc block.
   * If one of these line can not be found the line number will be set to null.
   *
   * @param string $theSourceCode The source code of the PHP file.
   *
   * @return array With the 3 line numbers as described.
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
          // Step 4: Find en of array declaration.
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
   * Create array declaration ($length, $key, $mask) for each database ID.
   */
  private function generateConstants()
  {
    foreach ($this->myTables as $table)
    {
      // Skip the table is the table must be ignored.
      if (!in_array($table["table_name"], $this->myConfig['ignore']))
      {
        if (!isset($this->myConfig['constants'][$table["table_name"]]))
        {
          // Key and mask is not yet defined for $label. Generate key and mask.
          print_r("Generating key and mask for label '{$table["table_name"]}'.\n");

          $this->myConfig['constants'][$table["table_name"]] = $this->getLabel($table);
        }
      }
    }

    // Save the configuration file.
    $this->rewriteConfig();
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Retrieves metadata about the auto increment columns.
   */
  private function getDatabaseIds()
  {
    $query = "
select table_name
,      column_name
,      data_type
from       information_schema.columns
where table_schema = database()
and   extra        = 'auto_increment'
order by table_name";

    $database = $this->myConfig['database'];
    StaticDataLayer::connect($database['host_name'],
                             $database['user_name'],
                             $database['password'],
                             $database['database_name']);
    $this->myTables = StaticDataLayer::executeRows($query);
    StaticDataLayer::disconnect();
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Compares .... xxxx
   *
   * @param $a
   * @param $b
   *
   * @return int
   */
  public static function compare($a, $b)
  {
    if (strtolower($a['label'])==strtolower($b['label']))
      return 0;

    return (strtolower($a['label'])>strtolower($b['label'])) ? 1 : -1;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Returns PHP snippet with an array declaration for reference obfuscator.
   *
   * @return array
   * @throws \BuildException
   */
  private function makeVariableStatements()
  {
    // Sort constants by label.
    $sort_result = uasort($this->myConfig['constants'], '\\SetBased\\Abc\\Obfuscator\\ReferenceObfuscatorAutomator::compare');
    if ($sort_result==false)
      throw new \BuildException("Sorting failed");

    $variable = "[\n";
    foreach ($this->myConfig['constants'] as $key => $value)
    {
      $variable .= sprintf("  '%s' => [%s, %s, %s],\n", $value["label"], $value["size"], $value["key"], $value["mask"]);
    }
    $variable .= ']';
    $constants[] = sprintf("%s = %s;", $this->myConfig['variable'], $variable, true);

    return $constants;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Saves the configuration data to the configuration file.
   */
  private function rewriteConfig()
  {
    // Sort array with labels, keys and masks by label.
    ksort($this->myConfig['constants']);

    Util::writeTwoPhases($this->myConfigFileName, json_encode($this->myConfig, JSON_PRETTY_PRINT));
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Insert new and replace old (if any) array declaration for reference obfuscator in a PHP source file.
   */
  private function writeConstant()
  {
    $source = file_get_contents($this->myConfig['file']);
    if ($source===false)
    {
      throw new RuntimeException("Unable the open file '%s'.", $this->myConfig['file']);
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
    $tmp1         = array_splice($source_lines, 0, $line_numbers[1]);
    $tmp2         = array_splice($source_lines, (isset($line_numbers[2])) ? $line_numbers[2] - $line_numbers[1] : 0);
    $source_lines = array_merge($tmp1, $constants, $tmp2);

    // Save the file.
    Util::writeTwoPhases($this->myConfig['file'], implode("\n", $source_lines));
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Returns the label of a table based on the metadata of the table.
   *
   * @param array $theTable The metadata of the table. The array has the following keys:
   *                        <ul>
   *                        <li> table_name   The name of the table.
   *                        <li> column_name  The name of the autoincrement column.
   *                        <li> column_type  The data type of the autoincrement column.
   *                        </ul>
   *
   * @return array
   */
  public static function getLabel($theTable)
  {
    $size  = self::$myIntegerTypeSizes[$theTable['data_type']];
    $key   = rand(1, pow(2, 16) - 1);
    $mask  = rand(pow(2, 8 * $size - 1), pow(2, 8 * $size) - 1);
    $label = substr($theTable["column_name"], 0, strlen($theTable["column_name"]) - 3);

    return ['label' => $label, 'size' => $size, 'key' => $key, 'mask' => $mask];
  }
}

//----------------------------------------------------------------------------------------------------------------------
