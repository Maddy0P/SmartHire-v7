<?php
// ═════════════════════════════════════════════════════════════════════════════
//  SmartHire ATS V2 — includes/ats_ontology.php
//  The skill ontology: pure data, no logic. Deterministic, dependency-free,
//  unit-testable, and editable without touching engine code.
//
//  Structure:
//    SH_SKILLS       canonical => ['cat' => category, 'alias' => [aliases...]]
//    SH_CERTS        canonical certification => [aliases...]
//    SH_TITLE_FAMILIES  family => [equivalent job titles...]
//    SH_ACTION_VERBS / SH_WEAK_WORDS / SH_BUZZWORDS  (resume quality v2)
//
//  Matching rules implemented by the engine (ats_engine.php):
//    alias  → canonical      (ReactJS → react)         full credit
//    same category            (MySQL vs PostgreSQL)    partial "related" credit
// ═════════════════════════════════════════════════════════════════════════════

const SH_SKILLS = [
    // ── Programming languages ──
    'javascript' => ['cat' => 'language',  'alias' => ['js', 'ecmascript', 'es6', 'vanilla js']],
    'typescript' => ['cat' => 'language',  'alias' => ['ts']],
    'python'     => ['cat' => 'language',  'alias' => ['python3', 'py']],
    'java'       => ['cat' => 'language',  'alias' => ['core java', 'java se', 'j2ee', 'java ee']],
    'php'        => ['cat' => 'language',  'alias' => ['php8', 'php 8', 'php7']],
    'c++'        => ['cat' => 'language',  'alias' => ['cpp', 'c plus plus']],
    'c#'         => ['cat' => 'language',  'alias' => ['csharp', 'c sharp', '.net c#']],
    'go'         => ['cat' => 'language',  'alias' => ['golang']],
    'ruby'       => ['cat' => 'language',  'alias' => ['ruby on rails language']],
    'kotlin'     => ['cat' => 'language',  'alias' => []],
    'swift'      => ['cat' => 'language',  'alias' => []],
    'rust'       => ['cat' => 'language',  'alias' => []],
    'r'          => ['cat' => 'language',  'alias' => ['r language', 'r programming']],
    'sql'        => ['cat' => 'database',  'alias' => ['structured query language', 'sql queries']],

    // ── Frontend ──
    'react'      => ['cat' => 'frontend',  'alias' => ['reactjs', 'react.js', 'react js']],
    'angular'    => ['cat' => 'frontend',  'alias' => ['angularjs', 'angular.js', 'angular 2+']],
    'vue'        => ['cat' => 'frontend',  'alias' => ['vuejs', 'vue.js', 'vue js', 'vue3']],
    'next.js'    => ['cat' => 'frontend',  'alias' => ['nextjs', 'next js']],
    'html'       => ['cat' => 'frontend',  'alias' => ['html5']],
    'css'        => ['cat' => 'frontend',  'alias' => ['css3', 'scss', 'sass', 'less']],
    'tailwind'   => ['cat' => 'frontend',  'alias' => ['tailwindcss', 'tailwind css']],
    'bootstrap'  => ['cat' => 'frontend',  'alias' => ['bootstrap5', 'bootstrap 5']],
    'jquery'     => ['cat' => 'frontend',  'alias' => []],
    'redux'      => ['cat' => 'frontend',  'alias' => ['redux toolkit']],

    // ── Backend / frameworks ──
    'node.js'    => ['cat' => 'backend',   'alias' => ['node', 'nodejs', 'node js']],
    'express'    => ['cat' => 'backend',   'alias' => ['expressjs', 'express.js']],
    'django'     => ['cat' => 'backend',   'alias' => []],
    'flask'      => ['cat' => 'backend',   'alias' => []],
    'fastapi'    => ['cat' => 'backend',   'alias' => ['fast api']],
    'spring'     => ['cat' => 'backend',   'alias' => ['spring boot', 'springboot', 'spring framework']],
    'laravel'    => ['cat' => 'backend',   'alias' => []],
    'rails'      => ['cat' => 'backend',   'alias' => ['ruby on rails', 'ror']],
    '.net'       => ['cat' => 'backend',   'alias' => ['dotnet', 'asp.net', 'dot net', '.net core']],
    'graphql'    => ['cat' => 'backend',   'alias' => ['graph ql']],
    'rest api'   => ['cat' => 'backend',   'alias' => ['rest', 'restful', 'restful api', 'rest apis', 'web api']],
    'microservices' => ['cat' => 'backend','alias' => ['micro services', 'micro-services']],

    // ── Databases ──
    'postgresql' => ['cat' => 'database',  'alias' => ['postgres', 'pgsql', 'psql', 'neon']],
    'mysql'      => ['cat' => 'database',  'alias' => ['mariadb', 'maria db']],
    'mongodb'    => ['cat' => 'database',  'alias' => ['mongo', 'mongo db']],
    'redis'      => ['cat' => 'database',  'alias' => []],
    'sqlite'     => ['cat' => 'database',  'alias' => ['sqlite3']],
    'oracle db'  => ['cat' => 'database',  'alias' => ['oracle database', 'oracle sql', 'pl/sql', 'plsql']],
    'elasticsearch' => ['cat' => 'database','alias' => ['elastic search', 'elk']],
    'sql server' => ['cat' => 'database',  'alias' => ['mssql', 'ms sql', 'microsoft sql server', 't-sql', 'tsql']],
    'dynamodb'   => ['cat' => 'database',  'alias' => ['dynamo db']],

    // ── Cloud ──
    'aws'        => ['cat' => 'cloud',     'alias' => ['amazon web services', 'ec2', 's3', 'lambda', 'amazon aws']],
    'azure'      => ['cat' => 'cloud',     'alias' => ['microsoft azure']],
    'gcp'        => ['cat' => 'cloud',     'alias' => ['google cloud', 'google cloud platform']],
    'cloud computing' => ['cat' => 'cloud','alias' => ['cloud', 'cloud services', 'cloud native']],
    'serverless' => ['cat' => 'cloud',     'alias' => ['faas']],

    // ── DevOps ──
    'docker'     => ['cat' => 'devops',    'alias' => ['containers', 'containerization', 'containerisation']],
    'kubernetes' => ['cat' => 'devops',    'alias' => ['k8s', 'kube']],
    'terraform'  => ['cat' => 'devops',    'alias' => ['iac', 'infrastructure as code']],
    'jenkins'    => ['cat' => 'devops',    'alias' => []],
    'ci/cd'      => ['cat' => 'devops',    'alias' => ['cicd', 'ci cd', 'continuous integration', 'continuous deployment', 'devops pipeline', 'github actions', 'gitlab ci']],
    'linux'      => ['cat' => 'devops',    'alias' => ['ubuntu', 'unix', 'bash', 'shell scripting', 'shell']],
    'nginx'      => ['cat' => 'devops',    'alias' => []],
    'ansible'    => ['cat' => 'devops',    'alias' => []],

    // ── Version control ──
    'git'        => ['cat' => 'tools',     'alias' => ['github', 'gitlab', 'bitbucket', 'version control']],
    'jira'       => ['cat' => 'tools',     'alias' => ['atlassian jira']],
    'agile'      => ['cat' => 'process',   'alias' => ['scrum', 'kanban', 'agile methodology', 'sprint planning']],

    // ── Data / ML ──
    'machine learning' => ['cat' => 'ml',  'alias' => ['ml', 'machine-learning']],
    'deep learning'    => ['cat' => 'ml',  'alias' => ['dl', 'neural networks', 'neural network']],
    'artificial intelligence' => ['cat' => 'ml', 'alias' => ['ai']],
    'nlp'        => ['cat' => 'ml',        'alias' => ['natural language processing']],
    'tensorflow' => ['cat' => 'ml',        'alias' => ['tf']],
    'pytorch'    => ['cat' => 'ml',        'alias' => ['torch']],
    'scikit-learn' => ['cat' => 'ml',      'alias' => ['sklearn', 'scikit learn']],
    'pandas'     => ['cat' => 'data',      'alias' => []],
    'numpy'      => ['cat' => 'data',      'alias' => []],
    'power bi'   => ['cat' => 'data',      'alias' => ['powerbi', 'power-bi']],
    'tableau'    => ['cat' => 'data',      'alias' => []],
    'excel'      => ['cat' => 'data',      'alias' => ['ms excel', 'microsoft excel', 'advanced excel']],
    'data analysis' => ['cat' => 'data',   'alias' => ['data analytics', 'data analyst']],
    'data visualization' => ['cat' => 'data', 'alias' => ['data visualisation', 'dashboards']],
    'etl'        => ['cat' => 'data',      'alias' => ['data pipelines', 'data pipeline']],
    'spark'      => ['cat' => 'data',      'alias' => ['apache spark', 'pyspark']],
    'hadoop'     => ['cat' => 'data',      'alias' => []],
    'kafka'      => ['cat' => 'data',      'alias' => ['apache kafka']],

    // ── Security ──
    'cybersecurity' => ['cat' => 'security', 'alias' => ['cyber security', 'information security', 'infosec']],
    'penetration testing' => ['cat' => 'security', 'alias' => ['pentesting', 'pen testing', 'ethical hacking']],
    'owasp'      => ['cat' => 'security',  'alias' => ['owasp top 10']],
    'siem'       => ['cat' => 'security',  'alias' => ['splunk', 'security monitoring']],
    'network security' => ['cat' => 'security', 'alias' => ['firewalls', 'vpn', 'ids/ips']],

    // ── Mobile ──
    'react native' => ['cat' => 'mobile',  'alias' => ['reactnative', 'react-native']],
    'flutter'    => ['cat' => 'mobile',    'alias' => ['dart']],
    'android'    => ['cat' => 'mobile',    'alias' => ['android development', 'android studio']],
    'ios'        => ['cat' => 'mobile',    'alias' => ['ios development', 'swiftui']],

    // ── Soft skills ──
    'communication' => ['cat' => 'soft',   'alias' => ['communication skills', 'verbal communication', 'written communication']],
    'leadership' => ['cat' => 'soft',      'alias' => ['team leadership', 'team lead', 'mentoring', 'mentorship']],
    'teamwork'   => ['cat' => 'soft',      'alias' => ['team player', 'collaboration', 'cross-functional']],
    'problem solving' => ['cat' => 'soft', 'alias' => ['problem-solving', 'analytical skills', 'critical thinking']],
    'time management' => ['cat' => 'soft', 'alias' => ['prioritization', 'prioritisation']],
    'project management' => ['cat' => 'soft', 'alias' => ['pmp', 'project planning', 'stakeholder management']],
];

/** Human-readable category labels (for UI + transferable-skill explanations). */
const SH_SKILL_CATS = [
    'language' => 'Programming Languages', 'frontend' => 'Frontend',   'backend' => 'Backend',
    'database' => 'Databases',             'cloud'    => 'Cloud',      'devops'  => 'DevOps',
    'tools'    => 'Tools',                 'process'  => 'Process',    'ml'      => 'AI / ML',
    'data'     => 'Data & Analytics',      'security' => 'Security',   'mobile'  => 'Mobile',
    'soft'     => 'Soft Skills',
];

/** Certification catalog: canonical => detection aliases. */
const SH_CERTS = [
    'AWS Certified'             => ['aws certified', 'aws solutions architect', 'aws cloud practitioner', 'aws developer associate', 'aws sysops'],
    'Azure Certified'           => ['azure certified', 'az-900', 'az-104', 'az-204', 'az-305', 'microsoft certified: azure'],
    'Google Cloud Certified'    => ['google cloud certified', 'gcp certified', 'google associate cloud engineer', 'google professional cloud'],
    'Oracle Certified'          => ['oracle certified', 'ocp', 'oca', 'oracle certification'],
    'Cisco CCNA/CCNP'           => ['ccna', 'ccnp', 'cisco certified'],
    'CompTIA'                   => ['comptia', 'security+', 'network+', 'a+ certification', 'comptia a+'],
    'CEH'                       => ['ceh', 'certified ethical hacker'],
    'CISSP'                     => ['cissp'],
    'PMP'                       => ['pmp', 'project management professional', 'prince2'],
    'Scrum Certified'           => ['csm', 'certified scrum', 'psm i', 'psm ii', 'safe agilist'],
    'Kubernetes Certified'      => ['cka', 'ckad', 'certified kubernetes'],
    'Google Data Analytics'     => ['google data analytics', 'google analytics certification'],
    'Microsoft Certified'       => ['microsoft certified', 'mcsa', 'mcse'],
    'IBM Certified'             => ['ibm certified', 'ibm data science'],
    'Meta Certified'            => ['meta certified', 'meta front-end', 'meta back-end'],
    'Coursera/Udemy Course'     => ['coursera', 'udemy', 'linkedin learning', 'nptel', 'edx'],
];

/** Equivalent job-title families for experience matching. */
const SH_TITLE_FAMILIES = [
    'software engineering' => ['software engineer', 'software developer', 'backend engineer', 'backend developer',
                               'application developer', 'full stack developer', 'full-stack developer',
                               'fullstack developer', 'web developer', 'programmer', 'sde', 'member of technical staff'],
    'frontend'             => ['frontend developer', 'front-end developer', 'front end developer', 'ui developer', 'ui engineer'],
    'data'                 => ['data analyst', 'data scientist', 'data engineer', 'business analyst', 'bi analyst',
                               'analytics engineer', 'machine learning engineer', 'ml engineer'],
    'devops'               => ['devops engineer', 'site reliability engineer', 'sre', 'platform engineer',
                               'cloud engineer', 'infrastructure engineer', 'build engineer'],
    'security'             => ['security analyst', 'security engineer', 'cybersecurity analyst', 'soc analyst', 'penetration tester'],
    'qa'                   => ['qa engineer', 'test engineer', 'quality assurance', 'sdet', 'automation tester'],
    'management'           => ['engineering manager', 'tech lead', 'team lead', 'project manager', 'product manager', 'scrum master'],
    'design'               => ['ui/ux designer', 'ux designer', 'ui designer', 'product designer', 'graphic designer'],
];

/** Strong action verbs (Resume-Worded-style quality signal). */
const SH_ACTION_VERBS = [
    'achieved','architected','automated','built','created','delivered','designed','developed','drove','engineered',
    'established','implemented','improved','increased','launched','led','managed','mentored','migrated','optimized',
    'optimised','orchestrated','owned','reduced','refactored','resolved','scaled','shipped','spearheaded','streamlined',
    'transformed','accelerated','collaborated','deployed','integrated','initiated','modernized','pioneered','published','secured',
];

/** Weak words/phrases that dilute impact. */
const SH_WEAK_WORDS = [
    'responsible for','worked on','helped with','assisted with','involved in','participated in','duties included',
    'tasked with','familiar with','exposure to','knowledge of','various','stuff','things','etc',
];

/** Overused buzzwords (flag when frequent, not forbid). */
const SH_BUZZWORDS = [
    'synergy','go-getter','think outside the box','team player','hard working','hardworking','self-starter',
    'detail-oriented','detail oriented','results-driven','results driven','dynamic','proactive','passionate',
    'motivated','guru','ninja','rockstar','best of breed','value add','win-win',
];
