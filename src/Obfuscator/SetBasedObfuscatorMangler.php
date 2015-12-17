<?php
//----------------------------------------------------------------------------------------------------------------------
namespace SetBased\Abc\Obfuscator;

//----------------------------------------------------------------------------------------------------------------------
use SetBased\Abc\Error\RuntimeException;

/**
 * Class for deriving labels from table metadata.
 */
class SetBasedObfuscatorMangler implements ReferenceObfuscatorMangler
{
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
   * @return string
   */
  public static function getLabel($theTable)
  {
    $id = substr($theTable['column_name'], -strlen('_id'));
    if ($id=='_id')
      return substr($theTable['column_name'], 0, -strlen('_id'));
    else
      throw new RuntimeException("Trailing '_id' not found in column '%s' in table '%s'.", $theTable['column_name'], $theTable['table_name']);
  }

  //-------------------------------------------------------------------------------------------------------------------
}

//----------------------------------------------------------------------------------------------------------------------

