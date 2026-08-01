-- Create additional test databases if needed
CREATE DATABASE baander_test_parallel_1;
CREATE DATABASE baander_test_parallel_2;
CREATE DATABASE baander_test_parallel_3;
CREATE DATABASE baander_test_parallel_4;

-- Grant permissions
GRANT ALL PRIVILEGES ON DATABASE baander_test TO baander_test;
GRANT ALL PRIVILEGES ON DATABASE baander_test_parallel_1 TO baander_test;
GRANT ALL PRIVILEGES ON DATABASE baander_test_parallel_2 TO baander_test;
GRANT ALL PRIVILEGES ON DATABASE baander_test_parallel_3 TO baander_test;
GRANT ALL PRIVILEGES ON DATABASE baander_test_parallel_4 TO baander_test;