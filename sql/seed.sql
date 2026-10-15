-- CareerBridge — seed data.
-- Run AFTER database-part2.sql.
--
-- Every skill a company can attach to a job lives in `skills`.
-- Every skill in `skills` has at least one grooming resource, so a
-- failed candidate can never land on an empty grooming page.
--
-- To add a skill: insert it here AND give it a resource below.

USE careerbridge;

INSERT INTO skills (name) VALUES
  ('PHP'),
  ('MySQL'),
  ('JavaScript'),
  ('HTML & CSS'),
  ('Python'),
  ('Java'),
  ('C++'),
  ('React'),
  ('Node.js'),
  ('Git'),
  ('REST APIs'),
  ('Data Structures'),
  ('Algorithms'),
  ('Object-Oriented Programming'),
  ('Software Testing'),
  ('Linux'),
  ('Networking'),
  ('Cybersecurity Basics'),
  ('Machine Learning'),
  ('Data Analysis'),
  ('SQL Optimisation'),
  ('Communication'),
  ('Problem Solving'),
  ('Teamwork')
ON DUPLICATE KEY UPDATE name = VALUES(name);


-- Grooming resources.
-- skill_id is resolved by name so this stays correct no matter what
-- order the skills were inserted in.
--
-- min_watch_seconds: set to 0 to make a resource optional-to-watch
-- (the candidate still has to mark it complete). Set it to a real
-- duration to require watch time.
INSERT INTO grooming_resources (skill_id, title, video_url, min_watch_seconds)
SELECT s.id, r.title, r.video_url, r.min_watch_seconds
FROM (
  SELECT 'PHP' AS skill, 'PHP in 100 Minutes — Full Beginner Course' AS title,
         'https://www.youtube.com/watch?v=OK_JCtrrv-c' AS video_url, 600 AS min_watch_seconds
  UNION ALL SELECT 'PHP', 'PDO and Prepared Statements Explained',
         'https://www.youtube.com/watch?v=WI-oFLj67Q4', 300
  UNION ALL SELECT 'MySQL', 'MySQL Full Course for Beginners',
         'https://www.youtube.com/watch?v=7S_tz1z_5bA', 600
  UNION ALL SELECT 'MySQL', 'Database Joins Made Simple',
         'https://www.youtube.com/watch?v=9yeOJ0ZMUYw', 300
  UNION ALL SELECT 'JavaScript', 'JavaScript Fundamentals in One Hour',
         'https://www.youtube.com/watch?v=W6NZfCO5SIk', 600
  UNION ALL SELECT 'JavaScript', 'Async JavaScript: Callbacks, Promises, Async/Await',
         'https://www.youtube.com/watch?v=PoRJizFvM7s', 400
  UNION ALL SELECT 'HTML & CSS', 'HTML and CSS Crash Course',
         'https://www.youtube.com/watch?v=UB1O30fR-EE', 600
  UNION ALL SELECT 'HTML & CSS', 'Flexbox and Grid Layout',
         'https://www.youtube.com/watch?v=phWxA89Dy94', 400
  UNION ALL SELECT 'Python', 'Python for Beginners — Full Course',
         'https://www.youtube.com/watch?v=rfscVS0vtbw', 600
  UNION ALL SELECT 'Java', 'Java Programming Full Course',
         'https://www.youtube.com/watch?v=eIrMbAQSU34', 600
  UNION ALL SELECT 'C++', 'C++ Tutorial for Beginners',
         'https://www.youtube.com/watch?v=ZzaPdXTrSb8', 600
  UNION ALL SELECT 'React', 'React Crash Course for Beginners',
         'https://www.youtube.com/watch?v=w7ejDZ8SWv8', 600
  UNION ALL SELECT 'Node.js', 'Node.js Crash Course',
         'https://www.youtube.com/watch?v=fBNz5xF-Kx4', 600
  UNION ALL SELECT 'Git', 'Git and GitHub for Beginners',
         'https://www.youtube.com/watch?v=RGOj5yH7evk', 500
  UNION ALL SELECT 'REST APIs', 'What is a REST API?',
         'https://www.youtube.com/watch?v=lsMQRaeKNDk', 300
  UNION ALL SELECT 'Data Structures', 'Data Structures Explained',
         'https://www.youtube.com/watch?v=RBSGKlAvoiM', 600
  UNION ALL SELECT 'Algorithms', 'Algorithms and Big-O Notation',
         'https://www.youtube.com/watch?v=Mo4vesaut8g', 500
  UNION ALL SELECT 'Object-Oriented Programming', 'OOP Concepts in 10 Minutes',
         'https://www.youtube.com/watch?v=pTB0EiLXUC8', 300
  UNION ALL SELECT 'Software Testing', 'Software Testing Fundamentals',
         'https://www.youtube.com/watch?v=u6QfIXgjwGQ', 400
  UNION ALL SELECT 'Linux', 'Linux Command Line Basics',
         'https://www.youtube.com/watch?v=ZtqBQ68cfJc', 500
  UNION ALL SELECT 'Networking', 'Computer Networking Full Course',
         'https://www.youtube.com/watch?v=qiQR5rTSshw', 600
  UNION ALL SELECT 'Cybersecurity Basics', 'Cybersecurity Fundamentals',
         'https://www.youtube.com/watch?v=inWWhr5tnEA', 500
  UNION ALL SELECT 'Machine Learning', 'Machine Learning for Everybody',
         'https://www.youtube.com/watch?v=i_LwzRVP7bg', 600
  UNION ALL SELECT 'Data Analysis', 'Data Analysis with Python',
         'https://www.youtube.com/watch?v=r-uOLxNrNk8', 600
  UNION ALL SELECT 'SQL Optimisation', 'SQL Indexing and Query Performance',
         'https://www.youtube.com/watch?v=HubezKbFL7E', 400
  UNION ALL SELECT 'Communication', 'Communication Skills for the Workplace',
         'https://www.youtube.com/watch?v=HAnw168huqA', 300
  UNION ALL SELECT 'Problem Solving', 'How to Solve Coding Problems',
         'https://www.youtube.com/watch?v=GBuHSRDGZBY', 300
  UNION ALL SELECT 'Teamwork', 'Working Effectively in a Team',
         'https://www.youtube.com/watch?v=fUXdrl9ch_Q', 300
) AS r
JOIN skills s ON s.name = r.skill
ON DUPLICATE KEY UPDATE
  title             = VALUES(title),
  min_watch_seconds = VALUES(min_watch_seconds);


-- Sanity check. Both queries must return zero rows.
-- If the first returns anything, that skill has no grooming material
-- and a candidate failing on it will see an empty page.
SELECT s.id, s.name AS skill_with_no_resource
FROM skills s
LEFT JOIN grooming_resources g ON g.skill_id = s.id
WHERE g.id IS NULL;
