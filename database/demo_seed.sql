-- ═════════════════════════════════════════════════════════════════════════════
--  SmartHire v7 — OPTIONAL Demo / Presentation Seed  (database/demo_seed.sql)
--
--  Populates a realistic enterprise dataset: staff, candidates, jobs across all
--  categories, applications in every pipeline stage, interviews + feedback,
--  offers, notifications, audit trail, resume scans, and a professional
--  interview QUESTION BANK (technical + aptitude + behavioral, 3 difficulties,
--  subjective + MCQ).
--
--  ⚠ This file is intentionally SEPARATE from SmartHire_v7_PostgreSQL_Setup.sql:
--    production installs stay clean; demos run this one extra file.
--  Usage:   psql "$DATABASE_URL" -f database/SmartHire_v7_PostgreSQL_Setup.sql
--           psql "$DATABASE_URL" -f database/demo_seed.sql
--  Idempotent: guarded by a sentinel; re-running is a no-op.
--  All demo logins use the password:  password
-- ═════════════════════════════════════════════════════════════════════════════

DO $seed$
DECLARE
  pw CONSTANT text := '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'; -- "password"
BEGIN
IF EXISTS (SELECT 1 FROM candidates WHERE email = 'aarav.mehta@demomail.in') THEN
  RAISE NOTICE 'SmartHire demo data already seeded — skipping.';
  RETURN;
END IF;

-- ── Extra staff ───────────────────────────────────────────────────────────────
INSERT INTO users (name,email,password,role) VALUES
 ('Priya Nair',    'priya@smarthire.com',  pw, 'recruiter'),
 ('Vikram Singh',  'vikram@smarthire.com', pw, 'interviewer'),
 ('Sneha Kulkarni','sneha@smarthire.com',  pw, 'hr')
ON CONFLICT (email) DO NOTHING;

-- ── Candidates (realistic profiles) ──────────────────────────────────────────
INSERT INTO candidates (name,email,phone,position,skills,education,experience_years,status,ai_score,password,address,linkedin_url,github_url) VALUES
 ('Aarav Mehta','aarav.mehta@demomail.in','+91 98200 11223','Backend Developer','Python, Django, PostgreSQL, Redis, Docker, AWS','B.Tech Computer Science, VJTI Mumbai (2019)',5,'interviewed',86,pw,'Andheri West, Mumbai','https://linkedin.com/in/aaravmehta','https://github.com/aaravm'),
 ('Ishita Sharma','ishita.sharma@demomail.in','+91 99670 22334','Frontend Developer','React, TypeScript, Next.js, Tailwind, Redux, Jest','B.E. IT, Pune University (2020)',4,'scheduled',81,pw,'Baner, Pune','https://linkedin.com/in/ishitasharma','https://github.com/ishitas'),
 ('Rohan Deshpande','rohan.d@demomail.in','+91 98500 33445','Full Stack Developer','Node.js, Express, React, MongoDB, GraphQL, Docker','MCA, Symbiosis Pune (2018)',6,'hired',90,pw,'Kothrud, Pune','https://linkedin.com/in/rohandesh','https://github.com/rohand'),
 ('Ananya Iyer','ananya.iyer@demomail.in','+91 98840 44556','Data Analyst','SQL, Python, Pandas, Power BI, Excel, Tableau','B.Sc Statistics, Madras University (2021)',3,'interviewed',78,pw,'Adyar, Chennai','https://linkedin.com/in/ananyaiyer',NULL),
 ('Kabir Khan','kabir.khan@demomail.in','+91 98110 55667','DevOps Engineer','Kubernetes, Docker, Terraform, Jenkins, AWS, Linux, CI/CD','B.Tech ECE, Jamia Millia (2017)',7,'scheduled',88,pw,'Saket, New Delhi','https://linkedin.com/in/kabirkhan','https://github.com/kabirk'),
 ('Meera Pillai','meera.pillai@demomail.in','+91 98950 66778','Cybersecurity Analyst','SIEM, Splunk, Penetration Testing, OWASP, Network Security, Linux','M.Sc Cyber Security, Amrita (2020)',4,'pending',74,pw,'Kakkanad, Kochi','https://linkedin.com/in/meerapillai',NULL),
 ('Arjun Reddy','arjun.reddy@demomail.in','+91 96660 77889','Cloud Engineer','AWS, Azure, Terraform, Python, Serverless, Networking','B.Tech CSE, JNTU Hyderabad (2019)',5,'pending',83,pw,'Gachibowli, Hyderabad','https://linkedin.com/in/arjunreddy','https://github.com/arjunr'),
 ('Divya Menon','divya.menon@demomail.in','+91 99860 88990','UI/UX Designer','Figma, Adobe XD, Prototyping, User Research, HTML, CSS','B.Des, NID Bengaluru (2021)',3,'pending',71,pw,'Indiranagar, Bengaluru','https://linkedin.com/in/divyamenon',NULL),
 ('Siddharth Joshi','sid.joshi@demomail.in','+91 98220 99001','QA Engineer','Selenium, Cypress, API Testing, Java, TestNG, JIRA','B.E. Computer, MIT Pune (2019)',5,'rejected',58,pw,'Aundh, Pune','https://linkedin.com/in/sidjoshi',NULL),
 ('Neha Agarwal','neha.agarwal@demomail.in','+91 98300 10112','Business Analyst','SQL, Excel, Power BI, Requirements Gathering, Agile, JIRA','MBA, XLRI Jamshedpur (2020)',4,'pending',76,pw,'Salt Lake, Kolkata','https://linkedin.com/in/nehaagarwal',NULL),
 ('Farhan Sheikh','farhan.s@demomail.in','+91 99200 12123','Database Administrator','PostgreSQL, Oracle DB, MySQL, PL/SQL, Backup & Recovery, Linux','B.Sc IT, Mumbai University (2016)',8,'pending',80,pw,'Bandra East, Mumbai','https://linkedin.com/in/farhansheikh',NULL),
 ('Tanvi Kulkarni','tanvi.k@demomail.in','+91 98600 13134','Software Engineer','Java, Spring Boot, Microservices, MySQL, Kafka, Git','B.Tech IT, COEP Pune (2020)',4,'pending',84,pw,'Viman Nagar, Pune','https://linkedin.com/in/tanvik','https://github.com/tanvik');

-- ── Jobs across all categories ───────────────────────────────────────────────
INSERT INTO jobs (title,category_id,department,location,employment_type,experience_min,experience_max,salary_min,salary_max,openings,description,requirements,skills_required,status,posted_by,closes_on)
SELECT * FROM (VALUES
 ('Senior Backend Engineer',(SELECT id FROM job_categories WHERE slug='engineering'),'Platform','Pune (Hybrid)','full_time',4,8,1800000,2800000,2,
  'Own core services powering our recruitment platform. You will design REST APIs, model data in PostgreSQL, and ship reliable, observable services. 4+ years experience required. Bachelor degree required.',
  'Strong API design, SQL depth, testing culture. Nice to have: Redis, Docker, AWS certified.',
  'Python, Django, PostgreSQL, REST API','open',(SELECT id FROM users WHERE email='priya@smarthire.com'),CURRENT_DATE + 30),
 ('Frontend Developer (React)',(SELECT id FROM job_categories WHERE slug='engineering'),'Product','Remote (India)','remote',2,5,1200000,2000000,3,
  'Build polished, accessible interfaces in React and TypeScript. 2+ years experience. Portfolio expected.',
  'React, TypeScript, state management. Nice to have: Next.js, Tailwind, testing with Jest.',
  'React, TypeScript, CSS, JavaScript','open',(SELECT id FROM users WHERE email='priya@smarthire.com'),CURRENT_DATE + 21),
 ('Full Stack Developer',(SELECT id FROM job_categories WHERE slug='engineering'),'Product','Bengaluru','full_time',3,6,1500000,2400000,2,
  'End-to-end feature ownership across Node.js services and React frontends. 3+ years experience. Bachelor degree preferred.',
  'Node.js, Express, React, MongoDB. Nice to have: GraphQL, Docker.',
  'Node.js, React, MongoDB, JavaScript','open',(SELECT id FROM users WHERE email='hr@smarthire.com'),CURRENT_DATE + 25),
 ('Data Analyst',(SELECT id FROM job_categories WHERE slug='data-analytics'),'Analytics','Mumbai','full_time',2,5,900000,1600000,1,
  'Turn hiring-funnel data into insight. Build dashboards, define metrics, partner with product. 2+ years experience. Bachelor degree required.',
  'SQL fluency, Excel, one BI tool. Nice to have: Python, Power BI certification.',
  'SQL, Excel, Power BI, Data Analysis','open',(SELECT id FROM users WHERE email='hr@smarthire.com'),CURRENT_DATE + 20),
 ('DevOps Engineer',(SELECT id FROM job_categories WHERE slug='devops-cloud'),'Infrastructure','Pune','full_time',4,8,2000000,3200000,1,
  'Own CI/CD, Kubernetes clusters, and infrastructure-as-code on AWS. 4+ years experience. CKA or AWS certification preferred.',
  'Kubernetes, Terraform, CI/CD pipelines, Linux. Nice to have: Ansible, monitoring stacks.',
  'Kubernetes, Docker, Terraform, AWS, CI/CD','open',(SELECT id FROM users WHERE email='priya@smarthire.com'),CURRENT_DATE + 35),
 ('Cloud Engineer (AWS/Azure)',(SELECT id FROM job_categories WHERE slug='devops-cloud'),'Infrastructure','Hyderabad','full_time',3,6,1600000,2600000,2,
  'Design and operate multi-cloud workloads. 3+ years experience. AWS certified preferred.',
  'AWS core services, networking, IaC. Nice to have: Azure, serverless.',
  'AWS, Azure, Terraform, Python','open',(SELECT id FROM users WHERE email='hr@smarthire.com'),CURRENT_DATE + 28),
 ('Cybersecurity Analyst',(SELECT id FROM job_categories WHERE slug='engineering'),'Security','Remote (India)','remote',3,6,1400000,2200000,1,
  'Monitor, detect, respond. Run the SIEM, tune detections, lead incident response drills. 3+ years experience. CEH or CompTIA Security+ preferred.',
  'SIEM operations, OWASP, network security fundamentals, Linux.',
  'SIEM, OWASP, Network Security, Linux','open',(SELECT id FROM users WHERE email='priya@smarthire.com'),CURRENT_DATE + 18),
 ('UI/UX Designer',(SELECT id FROM job_categories WHERE slug='design'),'Design','Bengaluru (Hybrid)','full_time',2,5,1000000,1800000,1,
  'Own flows from research to polished handoff for recruiter and candidate experiences. 2+ years experience. Portfolio required.',
  'Figma mastery, prototyping, user research. Nice to have: HTML/CSS.',
  'Figma, Prototyping, User Research','open',(SELECT id FROM users WHERE email='hr@smarthire.com'),CURRENT_DATE + 24),
 ('QA Automation Engineer',(SELECT id FROM job_categories WHERE slug='engineering'),'Quality','Pune','full_time',3,6,1200000,2000000,1,
  'Build the automation safety net: UI + API suites in CI. 3+ years experience.',
  'Selenium or Cypress, API testing, Java or JavaScript. Nice to have: performance testing.',
  'Selenium, API Testing, Java','paused',(SELECT id FROM users WHERE email='priya@smarthire.com'),CURRENT_DATE + 40),
 ('Product Manager — Hiring Platform',(SELECT id FROM job_categories WHERE slug='product'),'Product','Mumbai','full_time',5,9,2500000,4000000,1,
  'Drive roadmap for the ATS and analytics suite. 5+ years experience. MBA preferred.',
  'Discovery, stakeholder management, analytics fluency. Nice to have: recruitment-tech domain.',
  'Project Management, Agile, Data Analysis','draft',(SELECT id FROM users WHERE email='admin@smarthire.com'),CURRENT_DATE + 45)
) AS v(title,category_id,department,location,employment_type,experience_min,experience_max,salary_min,salary_max,openings,description,requirements,skills_required,status,posted_by,closes_on)
WHERE NOT EXISTS (SELECT 1 FROM jobs j WHERE j.title = v.title);

-- ── Applications across every stage ──────────────────────────────────────────
INSERT INTO job_applications (job_id,candidate_id,cover_note,stage,ats_score,skill_match,experience_match,education_match,resume_quality,interview_score,final_score,rejection_reason,applied_at)
SELECT j.id, c.id, v.note, v.stage, v.ats, v.skill, v.exp, v.edu, v.qual, v.iv, v.fin, v.rej, NOW() - (v.days || ' days')::interval
FROM (VALUES
 ('aarav.mehta@demomail.in','Senior Backend Engineer','5 years of Django + PostgreSQL at scale; keen on platform work.','interview_completed',86,95,90,80,78,82,84,NULL,12),
 ('tanvi.k@demomail.in','Senior Backend Engineer','Java/Spring background, strong SQL; quick to pick up Python.','shortlisted',72,55,85,80,74,NULL,72,NULL,9),
 ('farhan.s@demomail.in','Senior Backend Engineer','8 years of database engineering; moving toward backend.','ats_analysis',68,50,95,70,70,NULL,68,NULL,3),
 ('ishita.sharma@demomail.in','Frontend Developer (React)','React+TS daily for 4 years; shipped 3 production apps.','interview_scheduled',88,100,85,80,80,NULL,88,NULL,8),
 ('divya.menon@demomail.in','Frontend Developer (React)','Designer who codes; strong CSS, learning TypeScript.','resume_screening',61,45,60,75,72,NULL,61,NULL,2),
 ('rohan.d@demomail.in','Full Stack Developer','Full-stack across Node/React/Mongo for 6 years.','joined',90,100,95,85,82,88,89,NULL,30),
 ('tanvi.k@demomail.in','Full Stack Developer','Backend-heavy full-stack profile.','rejected',64,50,80,80,70,NULL,64,'Stack mismatch — Java-centric profile for a Node.js role',15),
 ('ananya.iyer@demomail.in','Data Analyst','SQL + Power BI daily; built exec dashboards at current firm.','offer_released',84,90,80,85,76,79,82,NULL,16),
 ('neha.agarwal@demomail.in','Data Analyst','BA with strong SQL and stakeholder skills.','online_test',75,75,80,90,72,NULL,75,NULL,6),
 ('kabir.khan@demomail.in','DevOps Engineer','CKA holder; run 40-node clusters with Terraform + GitOps.','interview_scheduled',91,100,95,75,80,NULL,91,NULL,7),
 ('arjun.reddy@demomail.in','DevOps Engineer','Cloud engineer expanding into platform work.','shortlisted',77,70,85,80,74,NULL,77,NULL,4),
 ('arjun.reddy@demomail.in','Cloud Engineer (AWS/Azure)','AWS SA-Associate; five years across EC2/S3/Lambda/VPC.','shortlisted',85,95,85,80,76,NULL,85,NULL,5),
 ('meera.pillai@demomail.in','Cybersecurity Analyst','SOC L2; Splunk detections, OWASP assessments, IR drills.','online_test',79,85,80,90,70,NULL,79,NULL,5),
 ('divya.menon@demomail.in','UI/UX Designer','NID grad; end-to-end product design portfolio.','shortlisted',82,90,75,85,74,NULL,82,NULL,3),
 ('sid.joshi@demomail.in','QA Automation Engineer','Selenium+TestNG suites in CI for 5 years.','rejected',58,60,85,80,62,NULL,58,'Role paused — headcount frozen this quarter',11),
 ('aarav.mehta@demomail.in','Full Stack Developer','Also open to full-stack; strong API side.','applied',70,60,85,80,78,NULL,70,NULL,1)
) AS v(email,job,note,stage,ats,skill,exp,edu,qual,iv,fin,rej,days)
JOIN candidates c ON c.email = v.email
JOIN jobs j ON j.title = v.job
ON CONFLICT (job_id,candidate_id) DO NOTHING;

-- pipeline history for the hired candidate (realistic audit trail)
INSERT INTO application_events (application_id,from_stage,to_stage,note,actor_role,created_at)
SELECT a.id, e.f, e.t, e.n, 'recruiter', NOW() - (e.d || ' days')::interval
FROM job_applications a
JOIN candidates c ON c.id=a.candidate_id AND c.email='rohan.d@demomail.in'
JOIN jobs j ON j.id=a.job_id AND j.title='Full Stack Developer'
CROSS JOIN (VALUES
 (NULL,'applied','Application received',30),('applied','shortlisted','Strong stack overlap',27),
 ('shortlisted','online_test','Assessment sent',25),('online_test','interview_scheduled','Scored 88% on test',22),
 ('interview_scheduled','interview_completed','Panel round done',18),('interview_completed','selected','Unanimous yes',16),
 ('selected','offer_released','Offer at 22 LPA',14),('offer_released','joined','Joined on schedule',2)
) AS e(f,t,n,d);

-- ── Interviews + feedback ────────────────────────────────────────────────────
INSERT INTO interviews (candidate_id,interviewer,scheduled_date,scheduled_time,type,mode,status,notes)
SELECT c.id, v.iv, CURRENT_DATE + v.d, v.t::time, v.ty, v.mo, v.st, v.no
FROM (VALUES
 ('aarav.mehta@demomail.in','Rahul Sharma',-5,'11:00','technical','online','completed','Deep Django + SQL round; strong systems thinking.'),
 ('rohan.d@demomail.in','Vikram Singh',-18,'14:30','technical','online','completed','Full-stack pairing session; excellent.'),
 ('ananya.iyer@demomail.in','Rahul Sharma',-3,'10:00','technical','online','completed','SQL + case study; clear communicator.'),
 ('ishita.sharma@demomail.in','Vikram Singh',2,'15:00','technical','online','scheduled','React deep-dive planned.'),
 ('kabir.khan@demomail.in','Rahul Sharma',3,'11:30','technical','online','scheduled','K8s + IaC scenario round.'),
 ('rohan.d@demomail.in','HR Manager',-16,'16:00','hr','online','completed','Culture + expectations aligned.')
) AS v(email,iv,d,t,ty,mo,st,no)
JOIN candidates c ON c.email = v.email;

INSERT INTO results (interview_id,candidate_id,technical_score,communication,problem_solving,cultural_fit,overall_score,recommendation,feedback)
SELECT i.id, i.candidate_id, v.ts, v.cm, v.ps, v.cf, v.ov, v.rec, v.fb
FROM (VALUES
 ('aarav.mehta@demomail.in','technical',82,78,85,80,82,'yes','Solid API design instincts; would pair well with platform team.'),
 ('rohan.d@demomail.in','technical',90,85,88,86,88,'strong_yes','Rare end-to-end depth. Hire.'),
 ('ananya.iyer@demomail.in','technical',80,84,76,78,79,'yes','Great dashboard storytelling; SQL solid.')
) AS v(email,ty,ts,cm,ps,cf,ov,rec,fb)
JOIN candidates c ON c.email=v.email
JOIN interviews i ON i.candidate_id=c.id AND i.type=v.ty AND i.status='completed';

-- ── Offers ───────────────────────────────────────────────────────────────────
INSERT INTO offers (application_id,candidate_id,job_id,designation,ctc,joining_date,letter_body,status,released_by,released_at,responded_at)
SELECT a.id,a.candidate_id,a.job_id,v.desig,v.ctc,CURRENT_DATE + v.jd,v.body,v.st,(SELECT id FROM users WHERE email='hr@smarthire.com'),NOW() - (v.rd||' days')::interval, CASE WHEN v.st='joined' THEN NOW() - ((v.rd-3)||' days')::interval END
FROM (VALUES
 ('rohan.d@demomail.in','Full Stack Developer','Senior Full Stack Developer',2200000,-2,'We are delighted to offer you the position of Senior Full Stack Developer...','joined',14),
 ('ananya.iyer@demomail.in','Data Analyst','Data Analyst II',1450000,20,'We are pleased to offer you the position of Data Analyst II...','released',2)
) AS v(email,job,desig,ctc,jd,body,st,rd)
JOIN candidates c ON c.email=v.email
JOIN jobs j ON j.title=v.job
JOIN job_applications a ON a.candidate_id=c.id AND a.job_id=j.id;

-- ── Notifications + audit trail + a few resume scans ─────────────────────────
INSERT INTO notifications (user_id,candidate_id,type,message,is_read,created_at)
SELECT u.id,NULL,v.ty,v.msg,v.rd,NOW()-(v.d||' hours')::interval FROM (VALUES
 ('priya@smarthire.com','application','New application: Kabir Khan → DevOps Engineer',0,6),
 ('priya@smarthire.com','application','New application: Arjun Reddy → Cloud Engineer (AWS/Azure)',0,20),
 ('hr@smarthire.com','offer','Offer released to Ananya Iyer (Data Analyst II)',1,48),
 ('hr@smarthire.com','interview','Interview scheduled: Ishita Sharma (React deep-dive)',0,30),
 ('admin@smarthire.com','system','Weekly analytics digest is ready',1,72)
) AS v(email,ty,msg,rd,d) JOIN users u ON u.email=v.email;

INSERT INTO audit_logs (actor_type,actor_id,actor_email,action,entity,entity_id,detail,ip,created_at)
SELECT 'user',u.id,u.email,v.act,v.ent,NULL,v.det,'203.0.113.'||v.ip,NOW()-(v.d||' hours')::interval FROM (VALUES
 ('priya@smarthire.com','job_create','job','Posted: Senior Backend Engineer',12,240),
 ('priya@smarthire.com','stage_move','application','Aarav Mehta → interview_completed',12,96),
 ('hr@smarthire.com','offer_release','offer','Ananya Iyer — Data Analyst II @ ₹14.5L',15,48),
 ('hr@smarthire.com','stage_move','application','Rohan Deshpande → joined',15,44),
 ('admin@smarthire.com','login','auth','Successful login',9,26),
 ('priya@smarthire.com','applicants_export','applications','Exported 16 ranked rows (CSV)',12,20)
) AS v(email,act,ent,det,ip,d) JOIN users u ON u.email=v.email;

INSERT INTO resume_scans (candidate_id,candidate_name_free,position_applied,ats_score,contact_score,keyword_score,format_score,experience_score,education_score,action_verb_score,matched_keywords,missing_keywords,word_count,scanned_at)
SELECT c.id,c.name,v.pos,v.ats,90,v.kw,80,v.exp,85,v.av,v.mk,v.miss,v.wc,NOW()-(v.d||' days')::interval FROM (VALUES
 ('aarav.mehta@demomail.in','Backend Developer',86,82,90,78,'python, django, postgresql, rest api, docker','redis',412,12),
 ('kabir.khan@demomail.in','DevOps Engineer',91,94,95,82,'kubernetes, terraform, docker, aws, ci/cd','ansible',388,7),
 ('meera.pillai@demomail.in','Cybersecurity Analyst',79,76,80,74,'siem, splunk, owasp, linux','penetration testing',356,5)
) AS v(email,pos,ats,kw,exp,av,mk,miss,wc,d) JOIN candidates c ON c.email=v.email;

RAISE NOTICE 'SmartHire demo data seeded.';
END
$seed$;

-- ═════════════════════════════════════════════════════════════════════════════
--  INTERVIEW QUESTION BANK — professional, role-tagged, 3 difficulties.
--  Guarded separately so it can also be loaded standalone.
-- ═════════════════════════════════════════════════════════════════════════════
DO $qbank$
BEGIN
IF EXISTS (SELECT 1 FROM interview_questions WHERE question LIKE 'Explain the difference between a process and a thread%') THEN
  RAISE NOTICE 'Question bank already seeded — skipping.'; RETURN;
END IF;

-- ── Technical: subjective ─────────────────────────────────────────────────────
INSERT INTO interview_questions (category,difficulty,position_tag,question,expected_answer,max_score,question_type) VALUES
-- Software Engineer
('technical','easy','Software Engineer','Explain the difference between a process and a thread.','Process = isolated memory/resources; thread = lightweight unit sharing process memory; context-switch and communication trade-offs.',10,'subjective'),
('technical','medium','Software Engineer','What is idempotency in API design and why does it matter for retries?','Same request repeated → same effect; safe retries; PUT/DELETE idempotent, POST usually not; idempotency keys for payments.',10,'subjective'),
('technical','hard','Software Engineer','Design a rate limiter for a public API. Compare token bucket and sliding window.','Token bucket (burst-friendly, refill rate) vs sliding window log/counter (accuracy vs memory); distributed store (Redis), atomicity, per-key limits, 429 + Retry-After.',10,'subjective'),
('system_design','hard','Software Engineer','Design a URL shortener that handles 100M redirects/day.','Hashing/base62 ID gen, collision strategy, cache (Redis/CDN) for hot links, DB partitioning, 301 vs 302, analytics pipeline, rate limiting.',10,'subjective'),
-- Backend Developer
('technical','easy','Backend Developer','What is the N+1 query problem and how do you fix it?','One query per parent row; fix with JOINs, eager loading, batching (IN), or dataloader patterns.',10,'subjective'),
('technical','medium','Backend Developer','Compare optimistic vs pessimistic locking. When would you use each?','Optimistic: version check at write, high-read/low-conflict. Pessimistic: row locks, high-conflict critical sections; deadlock risk.',10,'subjective'),
('technical','hard','Backend Developer','How would you make a money-transfer endpoint safe under concurrency and retries?','DB transaction + row locking or serializable isolation, idempotency key, outbox for events, exactly-once semantics discussion.',10,'subjective'),
-- Frontend Developer
('technical','easy','Frontend Developer','What is the virtual DOM and why does React use it?','In-memory representation; diffing minimizes real DOM mutations; batched reconciliation for performance.',10,'subjective'),
('technical','medium','Frontend Developer','Explain code-splitting and lazy loading in a React app.','Dynamic import(), React.lazy/Suspense, route-based chunks; smaller initial bundle, faster first paint.',10,'subjective'),
('technical','hard','Frontend Developer','Your page has a Lighthouse performance score of 40. Walk through your optimization plan.','Measure (LCP/CLS/TBT), compress+lazy images, split bundles, preload critical assets, cache headers, remove render-blockers, font strategy, measure again.',10,'subjective'),
-- Full Stack
('technical','medium','Full Stack Developer','How do you keep frontend and backend types in sync in a TypeScript project?','Shared types package, OpenAPI/GraphQL codegen, tRPC/contract tests; CI check drift.',10,'subjective'),
('technical','hard','Full Stack Developer','Design real-time notifications end-to-end (server → browser).','WebSocket/SSE choice, pub/sub backbone, delivery guarantees, offline catch-up via cursor, auth on socket, scaling with sticky sessions or a broker.',10,'subjective'),
-- Data Analyst
('technical','easy','Data Analyst','Explain the difference between INNER JOIN and LEFT JOIN with an example.','INNER = intersection only; LEFT = all left rows + NULLs for no-match; e.g., all candidates with/without applications.',10,'subjective'),
('technical','medium','Data Analyst','A dashboard metric dropped 30% overnight. Describe your investigation process.','Check data freshness/pipeline, definition changes, segment drilldown, seasonality, upstream schema changes; communicate findings with confidence levels.',10,'subjective'),
('technical','hard','Data Analyst','Write a SQL approach to compute a 7-day rolling conversion rate per job posting.','Window functions: COUNT FILTER over frames or self-join on date range; handle sparse dates via calendar spine.',10,'subjective'),
-- Business Analyst
('technical','easy','Business Analyst','What is the difference between functional and non-functional requirements?','What the system does vs qualities (performance, security, usability); examples of each.',10,'subjective'),
('technical','medium','Business Analyst','Stakeholders give conflicting requirements. How do you resolve this?','Trace to business goals, quantify impact, facilitate trade-off workshop, escalate with options, document decisions.',10,'subjective'),
-- Cyber Security
('technical','easy','Cyber Security','Explain the difference between authentication and authorization.','AuthN = who you are (credentials/MFA); AuthZ = what you may do (roles/policies).',10,'subjective'),
('technical','medium','Cyber Security','How would you detect and respond to a brute-force attack on a login endpoint?','Rate limiting/lockout, log analysis + SIEM alerting on failure spikes, IP reputation, MFA, incident runbook.',10,'subjective'),
('technical','hard','Cyber Security','You find SQL injection in production. Walk through your incident response.','Contain (WAF rule/patch), assess blast radius via logs, rotate secrets, fix with parameterized queries, forensics, disclosure, postmortem + regression tests.',10,'subjective'),
-- Cloud Engineer
('technical','easy','Cloud Engineer','What is the difference between vertical and horizontal scaling?','Bigger machine vs more machines; stateless design, load balancing, cost/limit trade-offs.',10,'subjective'),
('technical','medium','Cloud Engineer','Design a highly-available web tier on AWS.','Multi-AZ ALB + ASG, health checks, stateless app, RDS multi-AZ, S3 for assets, Route53 failover.',10,'subjective'),
('technical','hard','Cloud Engineer','Your AWS bill doubled month-over-month. How do you find and fix the cause?','Cost Explorer breakdown by service/tag, detect unattached EBS/idle instances, right-sizing, savings plans, egress analysis, budgets + alerts.',10,'subjective'),
-- DevOps
('technical','easy','DevOps Engineer','What problem do containers solve compared to virtual machines?','Consistent runtime, lightweight isolation sharing kernel, faster start, image layering; VMs isolate full OS.',10,'subjective'),
('technical','medium','DevOps Engineer','Describe a zero-downtime deployment strategy for a web service.','Blue-green or rolling with health checks, readiness probes, DB migration strategy (expand-contract), automatic rollback triggers.',10,'subjective'),
('technical','hard','DevOps Engineer','A Kubernetes pod is CrashLoopBackOff in production. Debug it live.','kubectl describe/logs --previous, events, probes config, resource limits/OOM, image/env diffs, node issues; mitigation vs root cause.',10,'subjective'),
-- DBA
('technical','easy','Database Administrator','What is an index and what is its cost?','Sorted lookup structure speeding reads; costs writes + storage; choose columns by selectivity/query shape.',10,'subjective'),
('technical','medium','Database Administrator','How do you approach a slow query in PostgreSQL?','EXPLAIN (ANALYZE, BUFFERS), check row estimates vs actual, missing/composite indexes, statistics, rewrite joins, work_mem, caching.',10,'subjective'),
('technical','hard','Database Administrator','Design a backup and recovery strategy with a 15-minute RPO and 1-hour RTO.','Base backups + WAL archiving/streaming (PITR), replica promotion, tested restore runbooks, monitoring lag, offsite copies.',10,'subjective'),
-- UI/UX
('technical','easy','UI/UX Designer','What is the difference between UX and UI?','UX = end-to-end experience/flows/research; UI = visual and interactive layer executing it.',10,'subjective'),
('technical','medium','UI/UX Designer','How do you design an accessible form? Name concrete practices.','Labels tied to inputs, error text not color-only, focus order, contrast, touch targets, aria-describedby for errors.',10,'subjective'),
-- QA
('technical','easy','QA Engineer','What is the difference between smoke, regression, and sanity testing?','Smoke = build viability; regression = old features still work; sanity = focused re-check after a fix.',10,'subjective'),
('technical','medium','QA Engineer','How do you decide what to automate vs test manually?','Automate stable, repetitive, high-value paths; manual for exploratory/UX/one-offs; ROI + flakiness cost.',10,'subjective'),
('technical','hard','QA Engineer','Your UI test suite is flaky. Systematically de-flake it.','Quantify flake rate, root causes (waits, test data, order deps), explicit waits, isolated data, retries as symptom not cure, quarantine lane.',10,'subjective'),
-- HR Executive
('hr','easy','HR Executive','How do you handle a candidate who declines an offer at the last minute?','Understand reason, counter thoughtfully if warranted, keep pipeline warm, feed learning back into offer process.',10,'subjective'),
('hr','medium','HR Executive','A hiring manager insists on an unrealistic timeline. How do you manage expectations?','Data on funnel conversion + time-to-hire, propose trade-offs (scope/level/comp), agree checkpoints.',10,'subjective');

-- ── Behavioral & communication ────────────────────────────────────────────────
INSERT INTO interview_questions (category,difficulty,position_tag,question,expected_answer,max_score,question_type) VALUES
('behavioral','easy','General','Tell me about a time you received difficult feedback. What did you do?','STAR structure; specific change made; reflection.',10,'subjective'),
('behavioral','medium','General','Describe a conflict with a teammate and how you resolved it.','Direct conversation, seeking to understand, shared goal focus, outcome.',10,'subjective'),
('behavioral','medium','General','Tell me about a project that failed. What was your role and what did you learn?','Ownership without blame-shifting; concrete lesson applied later.',10,'subjective'),
('behavioral','hard','General','Describe a time you disagreed with your manager''s decision. What did you do?','Respectful challenge with data, disagree-and-commit, escalation judgment.',10,'subjective'),
('hr','easy','General','Why do you want to join this company?','Specific research on product/mission; alignment with skills and goals.',10,'subjective'),
('hr','medium','General','Where do you see yourself in three years?','Growth trajectory consistent with the role; realistic ambition.',10,'subjective');

-- ── MCQ: aptitude, logical reasoning, communication, technical ────────────────
INSERT INTO interview_questions (category,difficulty,position_tag,question,max_score,question_type,option_a,option_b,option_c,option_d,correct_option) VALUES
('mcq','easy','General Aptitude','A train covers 120 km in 2 hours. What is its average speed?',5,'mcq','40 km/h','50 km/h','60 km/h','80 km/h','c'),
('mcq','easy','General Aptitude','If 5 machines make 5 widgets in 5 minutes, how long do 100 machines take to make 100 widgets?',5,'mcq','5 minutes','100 minutes','20 minutes','1 minute','a'),
('mcq','medium','General Aptitude','The average of five consecutive odd numbers is 25. What is the largest?',5,'mcq','27','29','31','33','b'),
('mcq','medium','General Aptitude','A price is increased by 20% and then decreased by 20%. Net change?',5,'mcq','No change','4% decrease','4% increase','2% decrease','b'),
('mcq','hard','General Aptitude','Two pipes fill a tank in 12 and 18 minutes. With a drain emptying it in 36 minutes, how long to fill?',5,'mcq','9 minutes','10 minutes','12 minutes','8 minutes','a'),
('mcq','easy','Logical Reasoning','Find the next term: 2, 6, 12, 20, 30, ?',5,'mcq','40','42','44','36','b'),
('mcq','medium','Logical Reasoning','All roses are flowers. Some flowers fade quickly. Which conclusion is valid?',5,'mcq','All roses fade quickly','Some roses fade quickly','No valid conclusion about roses fading','Roses never fade','c'),
('mcq','medium','Logical Reasoning','If CODING is written as DPEJOH, how is SYSTEM written?',5,'mcq','TZTUFN','TZTUFM','TZSUFN','TXTUFN','a'),
('mcq','hard','Logical Reasoning','In a row of 40 people, A is 13th from the left and B is 9th from the right. How many people are between them?',5,'mcq','16','17','18','19','c'),
('mcq','easy','Communication','Choose the grammatically correct sentence:',5,'mcq','Each of the players have a locker','Each of the players has a locker','Each of the player have lockers','Each players has a locker','b'),
('mcq','medium','Communication','Pick the best professional closing for an email requesting a decision:',5,'mcq','Waiting…','Do the needful ASAP','Please share your decision by Friday so we can proceed','Reply fast','c'),
('mcq','easy','Software Engineer','What is the time complexity of binary search on a sorted array?',5,'mcq','O(n)','O(log n)','O(n log n)','O(1)','b'),
('mcq','medium','Software Engineer','Which data structure gives O(1) average lookup by key?',5,'mcq','Array','Linked list','Hash map','Binary search tree','c'),
('mcq','medium','Backend Developer','Which HTTP status code means "resource created"?',5,'mcq','200','201','204','301','b'),
('mcq','hard','Backend Developer','Which isolation level prevents phantom reads in PostgreSQL?',5,'mcq','Read Committed','Repeatable Read','Serializable','Read Uncommitted','c'),
('mcq','easy','Frontend Developer','Which hook manages local state in a React function component?',5,'mcq','useEffect','useState','useMemo','useRef','b'),
('mcq','medium','Frontend Developer','Which CSS unit is relative to the root font size?',5,'mcq','em','rem','vh','px','b'),
('mcq','easy','Data Analyst','Which SQL clause filters groups after aggregation?',5,'mcq','WHERE','HAVING','GROUP BY','ORDER BY','b'),
('mcq','medium','Data Analyst','Which chart best shows the distribution of a single numeric variable?',5,'mcq','Pie chart','Histogram','Line chart','Scatter plot','b'),
('mcq','medium','Cyber Security','Which of these best mitigates SQL injection?',5,'mcq','Input length limits','Parameterized queries','Hiding error messages','Base64 encoding input','b'),
('mcq','easy','Cyber Security','What does the S in HTTPS provide?',5,'mcq','Speed','Encryption in transit','Server-side caching','Compression','b'),
('mcq','medium','Cloud Engineer','Which AWS service is object storage?',5,'mcq','EBS','EFS','S3','RDS','c'),
('mcq','medium','DevOps Engineer','In Kubernetes, which object maintains a stable set of replica pods?',5,'mcq','Service','ReplicaSet','ConfigMap','Ingress','b'),
('mcq','hard','DevOps Engineer','Which deployment strategy shifts traffic gradually to the new version?',5,'mcq','Recreate','Blue-green','Canary','Shadow','c'),
('mcq','easy','Database Administrator','Which command removes all rows but keeps the table structure and resets quickly?',5,'mcq','DELETE','DROP','TRUNCATE','REMOVE','c'),
('mcq','medium','QA Engineer','Which testing level verifies interactions between integrated modules?',5,'mcq','Unit','Integration','System','Acceptance','b'),
('mcq','easy','UI/UX Designer','What is the recommended minimum touch-target size on mobile?',5,'mcq','16×16 px','24×24 px','44×44 px','60×60 px','c');

RAISE NOTICE 'Interview question bank seeded.';
END
$qbank$;
