<?php
//----------------------------------------------------------------------------------------------------------------------
namespace SetBased\Abc\Obfuscator\SetBased;

use SetBased\Abc\Error\RuntimeException;

//----------------------------------------------------------------------------------------------------------------------
/**
 * Class for deriving labels from tables following the SetBased's coding standards for databases.
 */
class ReferenceObfuscatorMangler implements \SetBased\Abc\Obfuscator\ReferenceObfuscatorMangler
{
  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Returns the label of a table based on the metadata of a table following the SetBased's coding standards for
   * databases. The alias (or label) of a table are the first three characters of its columns (that are not foreign
   * keys).
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
    if ($id!='_id')
    {
      throw new RuntimeException("Trailing '_id' not found in column '%s' of table '%s'.",
                                 $theTable['column_name'],
                                 $theTable['table_name']);
    }

    return substr($theTable['column_name'], 0, -strlen('_id'));
  }

  //-------------------------------------------------------------------------------------------------------------------
}

//----------------------------------------------------------------------------------------------------------------------

