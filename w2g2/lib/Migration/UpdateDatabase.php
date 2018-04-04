<?php

namespace OCA\w2g2\Migration;

use OCP\IDbConnection;

class UpdateDatabase {
    protected $tableName;
    protected $db;

    public function __construct(IDbConnection $dbConnection)
    {
        $this->tableName = "oc_locks_w2g2";
        $this->db = $dbConnection;
    }

    public function run()
    {
        if ( ! $this->shouldUpdate()) {
            return;
        }

        $this->update();

        return 'done';
    }

    protected function shouldUpdate()
    {
        $query = "SELECT column_name
                  FROM information_schema.columns
                  WHERE table_name = '" . $this->tableName . "' and column_name = 'name'";

        $result = $this->db->executeQuery($query)
            ->fetchAll();

        return is_array($result) && count($result) > 0;
    }

    protected function update()
    {
        $locksQuery = "SELECT * FROM " . $this->tableName;

        $locks = $this->db->executeQuery($locksQuery)
            ->fetchAll();

        $files = [];

        // Get all data in the table and store it temporarily to add it back later.
        if (count($locks) != 0) {
            $fileCacheQuery = "SELECT fileid FROM oc_filecache WHERE path=?";

            foreach ($locks as $lock) {
                $groupFolderIndex = strpos($lock['name'], '__groupfolders');
                $fileIndex = strpos($lock['name'], 'files/');
                $index = $groupFolderIndex ?: $fileIndex;

                if ($index) {
                    $fileName = substr($lock['name'], $index);

                    $result = $this->db->executeQuery($fileCacheQuery, [$fileName])
                        ->fetchAll();

                    // Check if the file with the given path exits.
                    if (
                        $result &&
                        is_array($result) &&
                        count($result) > 0 &&
                        array_key_exists('fileid', $result[0]) &&
                        $result[0]['fileid']
                    ) {
                        $files[] = [
                            'id' => $result[0]['fileid'],
                            'locked_by' => $lock['locked_by']
                        ];
                    }
                }
            }

            $deleteQuery = "DELETE FROM " . $this->tableName;

            $this->db->executeQuery($deleteQuery);
        }

        $renameQuery = "ALTER TABLE " . $this->tableName . " RENAME COLUMN name TO file_id";
        $typeQuery = "ALTER TABLE " . $this->tableName . " ALTER COLUMN file_id TYPE INT USING file_id::integer";

        $this->db->executeQuery($renameQuery);
        $this->db->executeQuery($typeQuery);

        // Add the data back in the table
        if (count($files) > 0) {
            $insertQuery = "INSERT INTO " . $this->tableName . " (file_id, locked_by) VALUES ";

            $len = count($files);
            for ($i = 0; $i < $len; $i++) {
                $insertQuery .= "('" . $files[$i]['id'] . "', '" . $files[$i]['locked_by'] . "')";

                // Add a trailing comma if not the last one.
                if ($i != $len - 1) {
                    $insertQuery .= ', ';
                }
            }

            $this->db->executeQuery($insertQuery);
        }
    }
}
