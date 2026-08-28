/**
 * AI Knowledge Check - Main JavaScript Module
 *
 * @package    mod_aiknowledgecheck
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('mod_aiknowledgecheck/knowledgecheck', ['jquery'], function ($) {
    'use strict';

    let config = {};
    let currentJobId = null;
    let statusPollingInterval = null;
    let statusPollFailures = 0;
    const MAX_POLL_FAILURES = 15;
    let quizData = null;
    let currentQuestionIndex = 0;
    let score = 0;
    let selectedAnswer = null;
    let audioElement = null;
    let audioPreloadCache = {}; // Pre-decoded Audio elements keyed by 'qi_ai' for zero-delay playback
    let audioContext = null;
    let currentAttemptId = null;
    let resumeFromIndex = 0;  // Question index to restore when continuing an attempt.
    let resumeAnswers = null; // BUG-SCORE-RESUME fix: saved server answers for score reconstruction.
    let quizAnswerLog = [];   // Per-question record for the results download feature.
    let currentAttemptNum = 1;   // Tracks which attempt number we are currently recording (1 = first, 2 = first retry, ...).
    // FIX-RACE-FINISH: track in-flight saveanswer calls so finishAttempt waits for them.
    let pendingSaves = 0;
    let pendingFinishAttempt = false;
    // M4: remember answers whose save failed, so we can retry them before finishing rather
    // than silently grading a student on answers the server never received.
    let failedSaves = {}; // keyed by questionId -> {answerIndex, freetextValue}
    let textSources = []; // Array of {text, questionCount}
    let regenerationCount = 0; // Track regeneration count for free/paid logic (first 3 free)
    let selectedKcJobLevels = [];   // Multi-select job levels (pill buttons)
    let selectedKcJobRoles  = [];   // Multi-select job roles (chips input)
    let isAddingMore = false;       // "Add More Questions" mode flag
    let existingQuizData = null;    // Preserved questions while adding more
    
    // Initialize Web Audio API for sound effects
    function getAudioContext() {
        if (!audioContext) {
            audioContext = new (window.AudioContext || window.webkitAudioContext)();
        }
        return audioContext;
    }
    
    // Play a success "ding" sound for correct answers
    function playCorrectSound() {
        try {
            var ctx = getAudioContext();
            var oscillator = ctx.createOscillator();
            var gainNode = ctx.createGain();
            
            oscillator.connect(gainNode);
            gainNode.connect(ctx.destination);
            
            oscillator.frequency.setValueAtTime(880, ctx.currentTime); // A5
            oscillator.frequency.setValueAtTime(1108.73, ctx.currentTime + 0.1); // C#6
            oscillator.type = 'sine';
            
            gainNode.gain.setValueAtTime(0.3, ctx.currentTime);
            gainNode.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.3);
            
            oscillator.start(ctx.currentTime);
            oscillator.stop(ctx.currentTime + 0.3);
        } catch (e) {
            console.log('[KC] Audio not supported:', e);
        }
    }
    
    // Play an incorrect "buzz" sound for wrong answers
    function playIncorrectSound() {
        try {
            var ctx = getAudioContext();
            var oscillator = ctx.createOscillator();
            var gainNode = ctx.createGain();
            
            oscillator.connect(gainNode);
            gainNode.connect(ctx.destination);
            
            oscillator.frequency.setValueAtTime(200, ctx.currentTime);
            oscillator.frequency.setValueAtTime(150, ctx.currentTime + 0.1);
            oscillator.type = 'sawtooth';
            
            gainNode.gain.setValueAtTime(0.2, ctx.currentTime);
            gainNode.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.25);
            
            oscillator.start(ctx.currentTime);
            oscillator.stop(ctx.currentTime + 0.25);
        } catch (e) {
            console.log('[KC] Audio not supported:', e);
        }
    }
    
    // Play a level complete fanfare for perfect score
    function playLevelCompleteSound() {
        try {
            var ctx = getAudioContext();
            var notes = [523.25, 659.25, 783.99, 1046.50]; // C5, E5, G5, C6
            var delay = 0;
            
            notes.forEach(function (freq, i) {
                var oscillator = ctx.createOscillator();
                var gainNode = ctx.createGain();
                
                oscillator.connect(gainNode);
                gainNode.connect(ctx.destination);
                
                oscillator.frequency.setValueAtTime(freq, ctx.currentTime + delay);
                oscillator.type = 'sine';
                
                gainNode.gain.setValueAtTime(0, ctx.currentTime + delay);
                gainNode.gain.linearRampToValueAtTime(0.3, ctx.currentTime + delay + 0.05);
                gainNode.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + delay + 0.4);
                
                oscillator.start(ctx.currentTime + delay);
                oscillator.stop(ctx.currentTime + delay + 0.4);
                
                delay += 0.15;
            });
            
            // Final chord
            setTimeout(function () {
                [523.25, 659.25, 783.99, 1046.50].forEach(function (freq) {
                    var osc = ctx.createOscillator();
                    var gain = ctx.createGain();
                    osc.connect(gain);
                    gain.connect(ctx.destination);
                    osc.frequency.setValueAtTime(freq, ctx.currentTime);
                    osc.type = 'sine';
                    gain.gain.setValueAtTime(0.15, ctx.currentTime);
                    gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.8);
                    osc.start(ctx.currentTime);
                    osc.stop(ctx.currentTime + 0.8);
                });
            }, 700);
        } catch (e) {
            console.log('[KC] Audio not supported:', e);
        }
    }
    
    // Create confetti animation
    function createConfetti() {
        var container = document.createElement('div');
        container.className = 'kc-confetti-container';
        container.id = 'kc-confetti';
        document.body.appendChild(container);
        
        var colors = ['#667eea', '#764ba2', '#10b981', '#f59e0b', '#ef4444', '#06b6d4', '#8b5cf6'];
        var confettiCount = 150;
        
        for (var i = 0; i < confettiCount; i++) {
            var confetti = document.createElement('div');
            confetti.className = 'kc-confetti';
            confetti.style.left = Math.random() * 100 + '%';
            confetti.style.backgroundColor = colors[Math.floor(Math.random() * colors.length)];
            confetti.style.animationDelay = Math.random() * 3 + 's';
            confetti.style.animationDuration = (Math.random() * 2 + 2) + 's';
            
            // Random shapes
            if (Math.random() > 0.5) {
                confetti.style.borderRadius = '50%';
            }
            
            container.appendChild(confetti);
        }
        
        // Remove confetti after animation
        setTimeout(function () {
            if (container.parentNode) {
                container.parentNode.removeChild(container);
            }
        }, 5000);
    }

    /**
     * Download a CSV question-mapping file for Excel.
     * Columns: [Criteria,] [Topic,] Question Number, Question Text, Option A-E, Correct Answer, Correct Option Text, Explanation.
     * Topic and Criteria columns are included only when at least one question has that data.
     */
    // ADD-KC-MEDIAPER-Q (v1.5.120): Extract a YouTube video ID from any standard YouTube URL.
    // Handles watch?v=, youtu.be/, /embed/, and /v/ formats.
    function extractYouTubeId(url) {
        if (!url) return '';
        var m = url.match(/(?:youtube\.com\/(?:watch\?(?:.*&)?v=|embed\/|v\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})/);
        return m ? m[1] : '';
    }

    function downloadExcelMapping() {
        if (!quizData || quizData.length === 0) {
            alert('No questions available. Generate questions first.');
            return;
        }

        var labels = ['A', 'B', 'C', 'D'];

        // Determine which optional columns to include.
        var hasTopics   = quizData.some(function (q) { return q.mappingTopic   && q.mappingTopic.trim();   });
        var hasCriteria = quizData.some(function (q) { return q.mappingCriteria && q.mappingCriteria.trim(); });

        // BOM for Excel UTF-8 compatibility.
        var bom = '\uFEFF';
        var csvRows = [];

        // Header row.
        var headers = [];
        if (hasCriteria) { headers.push('Criteria'); }
        if (hasTopics)   { headers.push('Topic'); }
        headers = headers.concat([
            'Question Number',
            'Question Text',
            'Option A',
            'Option B',
            'Option C',
            'Option D',
            'Option E',
            'Correct Answer',
            'Correct Option Text',
            'Explanation'
        ]);
        csvRows.push(headers.map(function (h) { return '"' + h + '"'; }).join(','));

        // Data rows.
        quizData.forEach(function (q, index) {
            var correctIdx = q.correctAnswer || 0;
            var correctLabel = labels[correctIdx] || 'A';
            var correctText = (q.options && q.options[correctIdx]) ? q.options[correctIdx] : '';
            var explanation = (q.explanations && q.explanations[correctIdx]) ? q.explanations[correctIdx] : '';

            var row = [];
            if (hasCriteria) { row.push('"' + (q.mappingCriteria || '').replace(/"/g, '""') + '"'); }
            if (hasTopics)   { row.push('"' + (q.mappingTopic   || '').replace(/"/g, '""') + '"'); }
            row = row.concat([
                index + 1,
                '"' + (q.question || '').replace(/"/g, '""') + '"',
                '"' + ((q.options && q.options[0]) || '').replace(/"/g, '""') + '"',
                '"' + ((q.options && q.options[1]) || '').replace(/"/g, '""') + '"',
                '"' + ((q.options && q.options[2]) || '').replace(/"/g, '""') + '"',
                '"' + ((q.options && q.options[3]) || '').replace(/"/g, '""') + '"',
                // FIX-KC-EDIT-SURVEY (v1.5.139): 5-point scales have a 5th option.
                '"' + ((q.options && q.options[4]) || '').replace(/"/g, '""') + '"',
                '"' + correctLabel + '"',
                '"' + correctText.replace(/"/g, '""') + '"',
                '"' + explanation.replace(/"/g, '""') + '"'
            ]);
            csvRows.push(row.join(','));
        });

        var csvContent = bom + csvRows.join('\n');
        var blob = new Blob([csvContent], {type: 'text/csv;charset=utf-8;'});
        var url = window.URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = 'question_mapping.csv';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        window.URL.revokeObjectURL(url);
    }

    const countryStates = {
        'Australia': [
            {value: 'Western Australia', label: 'Western Australia'},
            {value: 'Queensland', label: 'Queensland'},
            {value: 'New South Wales', label: 'New South Wales'},
            {value: 'Victoria', label: 'Victoria'},
            {value: 'South Australia', label: 'South Australia'},
            {value: 'Tasmania', label: 'Tasmania'},
            {value: 'Northern Territory', label: 'Northern Territory'},
            {value: 'Australian Capital Territory', label: 'ACT'}
        ],
        'New Zealand': [
            {value: 'Auckland', label: 'Auckland'},
            {value: 'Wellington', label: 'Wellington'},
            {value: 'Canterbury', label: 'Canterbury'},
            {value: 'Waikato', label: 'Waikato'},
            {value: 'Otago', label: 'Otago'}
        ],
        'United Kingdom': [
            {value: 'England', label: 'England'},
            {value: 'Scotland', label: 'Scotland'},
            {value: 'Wales', label: 'Wales'},
            {value: 'Northern Ireland', label: 'Northern Ireland'}
        ],
        'United States': [
            {value: 'California', label: 'California'},
            {value: 'Texas', label: 'Texas'},
            {value: 'Florida', label: 'Florida'},
            {value: 'New York', label: 'New York'},
            {value: 'Other US State', label: 'Other'}
        ],
        'Canada': [
            {value: 'Ontario', label: 'Ontario'},
            {value: 'Quebec', label: 'Quebec'},
            {value: 'British Columbia', label: 'British Columbia'},
            {value: 'Alberta', label: 'Alberta'}
        ],
        'Singapore': []
    };

    // Job levels for cascading dropdown.
    const jobLevels = [
        {value: 'all_levels', label: 'All Levels'},
        {value: 'entry', label: 'Entry Level'},
        {value: 'intermediate', label: 'Intermediate'},
        {value: 'senior', label: 'Senior'},
        {value: 'supervisor', label: 'Supervisor'},
        {value: 'manager', label: 'Manager'},
        {value: 'executive', label: 'Executive'}
    ];

    // Industry-specific job titles by level.
    const jobTitlesByIndustry = {
        'Agriculture, Forestry & Fishing': {
            'entry': ['Farm Hand', 'Station Hand', 'Agricultural Worker', 'Harvest Worker', 'Nursery Assistant', 'Trainee'],
            'intermediate': ['Farm Machinery Operator', 'Stock Handler', 'Irrigation Technician', 'Tractor Operator', 'Shearer', 'Livestock Handler'],
            'senior': ['Senior Farm Hand', 'Head Stockman', 'Agronomist', 'Horticulturist', 'Wool Classer', 'Farm Supervisor'],
            'supervisor': ['Farm Supervisor', 'Station Supervisor', 'Operations Supervisor', 'Crew Leader', 'Shed Manager'],
            'manager': ['Farm Manager', 'Station Manager', 'Agricultural Manager', 'Operations Manager', 'Dairy Manager'],
            'executive': ['General Manager', 'Agricultural Director', 'Regional Manager', 'CEO']
        },
        'Mining & Resources': {
            'entry': ['Mine Worker', 'Trainee Operator', 'Offsider', 'Mine Labourer', 'Surface Hand', 'Trade Assistant'],
            'intermediate': ['Haul Truck Operator', 'Excavator Operator', 'Drill Operator', 'Shot Firer', 'Fitter', 'Boilermaker', 'Electrician'],
            'senior': ['Senior Operator', 'Lead Fitter', 'Senior Electrician', 'Maintenance Specialist', 'Process Plant Operator', 'Geologist'],
            'supervisor': ['Shift Supervisor', 'Mine Supervisor', 'Maintenance Supervisor', 'Production Supervisor', 'Deputy'],
            'manager': ['Mine Manager', 'Operations Manager', 'Maintenance Manager', 'Safety Manager', 'Mining Engineer'],
            'executive': ['General Manager', 'Mining Director', 'Chief Operating Officer', 'CEO']
        },
        'Manufacturing': {
            'entry': ['Production Worker', 'Machine Operator Trainee', 'Process Worker', 'Assembly Worker', 'Packer', 'Factory Worker'],
            'intermediate': ['Machine Operator', 'CNC Operator', 'Quality Inspector', 'Maintenance Technician', 'Welder', 'Fabricator'],
            'senior': ['Senior Operator', 'Lead Technician', 'Toolmaker', 'Quality Technician', 'Process Technician', 'Maintenance Fitter'],
            'supervisor': ['Production Supervisor', 'Shift Supervisor', 'Line Supervisor', 'Quality Supervisor', 'Maintenance Supervisor'],
            'manager': ['Production Manager', 'Plant Manager', 'Operations Manager', 'Quality Manager', 'Safety Manager'],
            'executive': ['Manufacturing Director', 'General Manager', 'Chief Operations Officer', 'CEO']
        },
        'Electricity, Gas, Water & Waste': {
            'entry': ['Utility Worker', 'Trainee Technician', 'Labourer', 'Meter Reader', 'Trade Assistant'],
            'intermediate': ['Linesperson', 'Fitter', 'Electrician', 'Plumber', 'Plant Operator', 'Water Treatment Operator'],
            'senior': ['Senior Technician', 'Senior Electrician', 'Senior Operator', 'Network Technician', 'Master Tradesperson'],
            'supervisor': ['Crew Supervisor', 'Shift Supervisor', 'Field Supervisor', 'Works Supervisor', 'Operations Supervisor'],
            'manager': ['Operations Manager', 'Network Manager', 'Maintenance Manager', 'Project Manager', 'WHS Manager'],
            'executive': ['General Manager', 'Director of Operations', 'Chief Operating Officer', 'CEO']
        },
        'Construction': {
            'entry': ['Apprentice Carpenter', 'Labourer', 'Trade Assistant', 'Apprentice Electrician', 'Apprentice Plumber', 'Traffic Controller'],
            'intermediate': ['Carpenter', 'Electrician', 'Plumber', 'Bricklayer', 'Concreter', 'Painter', 'Tiler', 'Plasterer'],
            'senior': ['Lead Carpenter', 'Senior Electrician', 'Master Plumber', 'Site Supervisor', 'Safety Officer', 'Foreman'],
            'supervisor': ['Site Foreman', 'Construction Supervisor', 'Trade Supervisor', 'Works Supervisor', 'Safety Supervisor'],
            'manager': ['Construction Manager', 'Project Manager', 'Site Manager', 'Contracts Manager', 'Estimator'],
            'executive': ['Construction Director', 'General Manager', 'Operations Director', 'CEO']
        },
        'Wholesale Trade': {
            'entry': ['Warehouse Worker', 'Picker/Packer', 'Trainee', 'Sales Assistant', 'Delivery Driver', 'Stock Clerk'],
            'intermediate': ['Sales Representative', 'Account Executive', 'Forklift Operator', 'Inventory Controller', 'Customer Service Officer'],
            'senior': ['Senior Sales Representative', 'Key Account Manager', 'Senior Buyer', 'Purchasing Officer', 'Trade Specialist'],
            'supervisor': ['Warehouse Supervisor', 'Sales Supervisor', 'Team Leader', 'Logistics Supervisor', 'Inventory Supervisor'],
            'manager': ['Sales Manager', 'Warehouse Manager', 'Operations Manager', 'Regional Manager', 'Purchasing Manager'],
            'executive': ['General Manager', 'Director of Sales', 'CEO', 'Managing Director']
        },
        'Retail Trade': {
            'entry': ['Sales Assistant', 'Retail Trainee', 'Cashier', 'Checkout Operator', 'Stock Assistant', 'Customer Service Representative'],
            'intermediate': ['Senior Sales Assistant', 'Visual Merchandiser', 'Stock Controller', 'Department Specialist', 'Pharmacy Assistant'],
            'senior': ['Senior Sales Consultant', 'Department Specialist', 'Lead Sales Associate', 'Loss Prevention Officer'],
            'supervisor': ['Department Supervisor', 'Shift Leader', 'Team Supervisor', 'Floor Supervisor', 'Assistant Store Manager'],
            'manager': ['Store Manager', 'Assistant Store Manager', 'Area Manager', 'Retail Manager', 'Department Manager'],
            'executive': ['Regional Director', 'Retail Director', 'General Manager', 'Chief Retail Officer']
        },
        'Accommodation & Food Services': {
            'entry': ['Kitchen Hand', 'Food & Beverage Attendant', 'Housekeeping Attendant', 'Barista', 'Room Attendant', 'Cook'],
            'intermediate': ['Chef de Partie', 'Bartender', 'Waiter', 'Waitress', 'Receptionist', 'Concierge', 'Commis Chef'],
            'senior': ['Sous Chef', 'Head Chef', 'Head Bartender', 'Restaurant Supervisor', 'Senior Receptionist', 'Duty Manager'],
            'supervisor': ['Kitchen Supervisor', 'F&B Supervisor', 'Front Office Supervisor', 'Housekeeping Supervisor', 'Events Coordinator'],
            'manager': ['Restaurant Manager', 'Hotel Manager', 'F&B Manager', 'Front Office Manager', 'Executive Chef'],
            'executive': ['General Manager', 'Hotel Director', 'Regional Manager', 'Operations Director']
        },
        'Transport, Postal & Warehousing': {
            'entry': ['Warehouse Worker', 'Forklift Trainee', 'Delivery Driver', 'Picker/Packer', 'Logistics Assistant', 'Courier Driver'],
            'intermediate': ['Forklift Operator', 'Truck Driver', 'Bus Driver', 'Warehouse Operator', 'Dispatch Coordinator', 'Train Driver'],
            'senior': ['Senior Driver', 'Lead Warehouse Operator', 'Logistics Coordinator', 'Transport Coordinator', 'Heavy Vehicle Driver'],
            'supervisor': ['Warehouse Supervisor', 'Transport Supervisor', 'Dispatch Supervisor', 'Shift Supervisor', 'Fleet Coordinator'],
            'manager': ['Warehouse Manager', 'Logistics Manager', 'Transport Manager', 'Distribution Manager', 'Fleet Manager'],
            'executive': ['Operations Director', 'Logistics Director', 'Supply Chain Director', 'General Manager']
        },
        'Information Media & Telecommunications': {
            'entry': ['IT Support Trainee', 'Help Desk Officer', 'Junior Developer', 'IT Intern', 'Support Technician', 'Data Entry Operator'],
            'intermediate': ['Software Developer', 'System Administrator', 'Network Engineer', 'IT Support Specialist', 'Web Developer', 'Data Analyst'],
            'senior': ['Senior Developer', 'Senior Engineer', 'Solutions Architect', 'DevOps Engineer', 'Cybersecurity Analyst', 'Database Administrator'],
            'supervisor': ['IT Team Lead', 'Development Lead', 'Technical Lead', 'Infrastructure Lead', 'Network Administrator'],
            'manager': ['IT Manager', 'Development Manager', 'Project Manager', 'Infrastructure Manager', 'IT Services Manager'],
            'executive': ['IT Director', 'CTO', 'CIO', 'General Manager']
        },
        'Financial & Insurance Services': {
            'entry': ['Customer Service Officer', 'Administrative Assistant', 'Trainee', 'Data Entry Clerk', 'Bank Teller', 'Receptionist'],
            'intermediate': ['Financial Adviser', 'Insurance Agent', 'Loans Officer', 'Claims Officer', 'Underwriter', 'Accountant', 'Bookkeeper'],
            'senior': ['Senior Financial Adviser', 'Senior Analyst', 'Senior Underwriter', 'Portfolio Manager', 'Risk Analyst', 'Auditor'],
            'supervisor': ['Team Leader', 'Supervisor', 'Branch Supervisor', 'Claims Supervisor', 'Operations Supervisor'],
            'manager': ['Branch Manager', 'Claims Manager', 'Operations Manager', 'Risk Manager', 'Compliance Manager', 'Finance Manager'],
            'executive': ['CFO', 'General Manager', 'Director', 'CEO', 'Managing Director']
        },
        'Rental, Hiring & Real Estate': {
            'entry': ['Receptionist', 'Administrative Assistant', 'Trainee', 'Property Assistant', 'Customer Service Officer'],
            'intermediate': ['Property Manager', 'Real Estate Agent', 'Leasing Consultant', 'Rental Agent', 'Valuer', 'Sales Agent'],
            'senior': ['Senior Property Manager', 'Senior Real Estate Agent', 'Senior Valuer', 'Licensed Agent', 'Portfolio Manager'],
            'supervisor': ['Team Leader', 'Office Supervisor', 'Property Supervisor', 'Leasing Supervisor', 'Sales Supervisor'],
            'manager': ['Agency Principal', 'Property Manager', 'Operations Manager', 'Regional Manager', 'Sales Manager'],
            'executive': ['Director', 'General Manager', 'CEO', 'Managing Director', 'Principal']
        },
        'Professional, Scientific & Technical': {
            'entry': ['Graduate', 'Trainee', 'Junior Consultant', 'Administrative Assistant', 'Lab Assistant', 'Research Assistant'],
            'intermediate': ['Consultant', 'Analyst', 'Engineer', 'Scientist', 'Accountant', 'Lawyer', 'Architect'],
            'senior': ['Senior Consultant', 'Senior Engineer', 'Senior Analyst', 'Project Lead', 'Senior Scientist', 'Senior Associate'],
            'supervisor': ['Team Leader', 'Project Supervisor', 'Technical Lead', 'Section Leader', 'Engagement Lead'],
            'manager': ['Project Manager', 'Department Manager', 'Practice Manager', 'Operations Manager', 'Technical Manager'],
            'executive': ['Director', 'Partner', 'Principal', 'CEO', 'Managing Director', 'General Manager']
        },
        'Administrative & Support Services': {
            'entry': ['Cleaner', 'Security Guard', 'Receptionist', 'Administrative Assistant', 'Data Entry Clerk', 'Office Junior'],
            'intermediate': ['Office Administrator', 'Executive Assistant', 'Payroll Officer', 'HR Officer', 'Recruitment Consultant', 'Security Officer'],
            'senior': ['Senior Administrator', 'Senior HR Officer', 'Senior Recruiter', 'Office Manager', 'Facilities Coordinator'],
            'supervisor': ['Team Leader', 'Supervisor', 'Shift Supervisor', 'Security Supervisor', 'Cleaning Supervisor'],
            'manager': ['Office Manager', 'Facilities Manager', 'HR Manager', 'Operations Manager', 'Administration Manager'],
            'executive': ['General Manager', 'Director of Operations', 'CEO', 'Managing Director', 'COO']
        },
        'Public Administration & Safety': {
            'entry': ['Administrative Officer', 'Customer Service Officer', 'Trainee', 'Clerical Officer', 'Records Officer'],
            'intermediate': ['Policy Officer', 'Project Officer', 'Compliance Officer', 'Inspector', 'Case Worker', 'Firefighter', 'Police Officer', 'Paramedic'],
            'senior': ['Senior Officer', 'Senior Policy Analyst', 'Senior Inspector', 'Senior Case Worker', 'Senior Project Officer'],
            'supervisor': ['Team Leader', 'Coordinator', 'Supervisor', 'Section Leader', 'Unit Leader'],
            'manager': ['Manager', 'Branch Manager', 'Program Manager', 'Operations Manager', 'WHS Manager'],
            'executive': ['Director', 'Executive Director', 'Secretary', 'Deputy Secretary', 'CEO', 'General Manager']
        },
        'Education & Training': {
            'entry': ['Teacher Aide', 'Education Support Officer', 'Library Assistant', 'Tutor', 'Trainee Teacher', 'School Administration Officer'],
            'intermediate': ['Teacher', 'Primary School Teacher', 'Secondary School Teacher', 'Trainer', 'Assessor', 'Trainer and Assessor', 'TAFE Teacher', 'Learning Support Officer', 'Special Education Teacher'],
            'senior': ['Senior Teacher', 'Lead Teacher', 'Subject Coordinator', 'Senior Trainer', 'Curriculum Coordinator', 'Head of Department', 'VET Coordinator'],
            'supervisor': ['Head of Department', 'Year Level Coordinator', 'Faculty Head', 'Training Supervisor', 'Education Manager'],
            'manager': ['Assistant Principal', 'Deputy Principal', 'Training Manager', 'Education Manager', 'Program Manager', 'RTO Manager'],
            'executive': ['Principal', 'Director of Education', 'CEO', 'Executive Principal', 'Director of Training']
        },
        'Health Care & Social Assistance': {
            'entry': ['Healthcare Assistant', 'Patient Care Assistant', 'Personal Care Worker', 'Ward Clerk', 'Hospital Porter', 'Aged Care Worker'],
            'intermediate': ['Registered Nurse', 'Enrolled Nurse', 'Allied Health Assistant', 'Medical Receptionist', 'Dental Assistant', 'Pharmacy Assistant', 'Disability Support Worker'],
            'senior': ['Clinical Nurse Specialist', 'Senior Registered Nurse', 'Clinical Coordinator', 'Senior Therapist', 'Midwife', 'Physiotherapist', 'Occupational Therapist'],
            'supervisor': ['Nurse Unit Manager', 'Clinical Supervisor', 'Ward Manager', 'Team Leader', 'Practice Manager'],
            'manager': ['Nursing Manager', 'Clinical Services Manager', 'Health Services Manager', 'Practice Manager', 'Facility Manager'],
            'executive': ['Director of Nursing', 'Chief Medical Officer', 'Hospital Director', 'Chief Executive']
        },
        'Arts & Recreation Services': {
            'entry': ['Attendant', 'Trainee', 'Customer Service Officer', 'Lifeguard', 'Fitness Trainee', 'Usher'],
            'intermediate': ['Fitness Instructor', 'Personal Trainer', 'Recreation Officer', 'Event Coordinator', 'Sports Coach', 'Swim Teacher'],
            'senior': ['Senior Instructor', 'Head Coach', 'Senior Event Coordinator', 'Program Coordinator', 'Facility Coordinator', 'Aquatics Manager'],
            'supervisor': ['Team Leader', 'Shift Supervisor', 'Duty Manager', 'Program Supervisor', 'Operations Supervisor'],
            'manager': ['Facility Manager', 'Recreation Manager', 'Events Manager', 'Operations Manager', 'Sports Manager', 'Gym Manager'],
            'executive': ['General Manager', 'Director', 'CEO', 'Managing Director', 'Executive Director']
        },
        'Business Services': {
            'entry': ['Administrative Assistant', 'Receptionist', 'Trainee', 'Data Entry Clerk', 'Office Junior', 'Customer Service Officer'],
            'intermediate': ['Business Analyst', 'Account Manager', 'Marketing Coordinator', 'Project Coordinator', 'Client Services Officer', 'Office Administrator'],
            'senior': ['Senior Business Analyst', 'Senior Account Manager', 'Senior Consultant', 'Business Development Manager', 'Senior Project Officer', 'Senior Marketing Officer'],
            'supervisor': ['Team Leader', 'Operations Supervisor', 'Client Services Supervisor', 'Project Supervisor', 'Office Supervisor'],
            'manager': ['Operations Manager', 'Business Development Manager', 'Client Services Manager', 'General Manager', 'Marketing Manager', 'HR Manager'],
            'executive': ['Director', 'CEO', 'Managing Director', 'Chief Operating Officer', 'General Manager', 'Executive Director']
        },
        'Other Services': {
            'entry': ['Trainee', 'Assistant', 'Labourer', 'Cleaner', 'Customer Service Officer', 'Apprentice'],
            'intermediate': ['Technician', 'Tradesperson', 'Service Officer', 'Specialist', 'Coordinator', 'Officer'],
            'senior': ['Senior Technician', 'Lead Specialist', 'Senior Coordinator', 'Master Tradesperson', 'Senior Officer'],
            'supervisor': ['Team Leader', 'Supervisor', 'Shift Supervisor', 'Site Supervisor', 'Works Supervisor'],
            'manager': ['Operations Manager', 'Service Manager', 'Branch Manager', 'Regional Manager', 'WHS Manager'],
            'executive': ['General Manager', 'Director', 'CEO', 'Managing Director', 'Principal']
        },
        'default': {
            'entry': ['Trainee', 'Apprentice', 'Junior Worker', 'Assistant', 'Entry Level Worker'],
            'intermediate': ['Tradesperson', 'Technician', 'Operator', 'Officer', 'Coordinator'],
            'senior': ['Senior Technician', 'Lead Worker', 'Specialist', 'Senior Officer'],
            'supervisor': ['Team Leader', 'Supervisor', 'Foreman', 'Shift Supervisor'],
            'manager': ['Manager', 'Project Manager', 'Operations Manager', 'Department Manager'],
            'executive': ['Director', 'General Manager', 'Chief Officer', 'Executive']
        }
    };

    // Current selected industry for job title lookup
    let currentIndustry = '';

    function init(cfg) {
        config = cfg;
        console.log('[KC] init called, isTeacher:', config.isTeacher);
        
        bindEvents();

        // ADD-SURVEY-FREETEXT (v1.5.127): Show free-text questions textarea in survey mode.
        // SURVEY-MODE-UI (v1.5.128): Hide voiceover — surveys don't generate audio explanations.
        // Also pre-open the use-own-questions panel (most survey teachers supply their own questions)
        // and update the sublabel so teachers know the scale is applied automatically.
        if (config.surveyMode) {
            $('#freetext-questions-group').show();
            // Hide voiceover section — not applicable to surveys.
            $('#voiceover-toggle').prop('checked', false);
            $('#voice-settings-section').hide();
            if ($('#voiceover-section').length) { $('#voiceover-section').hide(); }
        }
        
        // Apply persisted voiceover settings from server config
        if (typeof config.voiceoverEnabled !== 'undefined') {
            var voEnabled = !!config.voiceoverEnabled;
            $('#voiceover-toggle').prop('checked', voEnabled);
            if (voEnabled) {
                $('#voice-settings-section').show();
            } else {
                $('#voice-settings-section').hide();
            }
        }
        if (config.voiceLanguage) {
            $('#voice-language').val(config.voiceLanguage);
        }
        if (config.voiceGender) {
            $('#voice-gender').val(config.voiceGender);
            handleGenderChange();
        }
        if (config.voiceStyle) {
            setTimeout(function () { $('#voice-style').val(config.voiceStyle); }, 50);
        }
        
        // Teacher-only initialization (credits, form dropdowns)
        if (config.isTeacher) {
            console.log('[KC] Teacher mode - fetching credits and industries');
            fetchCredits();
            fetchIndustries();
            
            // Check if questions already exist in the database
            checkExistingQuestions();
        } else {
            console.log('[KC] Student mode - skipping credits fetch');
        }
    }
    
    function checkExistingQuestions() {
        console.log('[KC] Checking for existing questions...');
        
        $.ajax({
            url: config.wwwroot + '/mod/aiknowledgecheck/ajax.php',
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'getquestions',
                sesskey: config.sesskey,
                cmid: config.cmid
            },
            success: function (response) {
                if (response.ok && response.questions && response.questions.length > 0) {
                    console.log('[KC] Found existing questions:', response.questions.length);
                    
                    // Transform database format to quiz format
                    quizData = response.questions.map(function (q) {
                        // ADD-SURVEY-FREETEXT (v1.5.127): guard against empty options arrays for freetext questions.
                        var opts = q.options || [];
                        return {
                            id: q.id,
                            question: q.question,
                            options: opts.map(function (o) { return o.text || ''; }),
                            explanations: opts.map(function (o) { return o.explanation || ''; }),
                            correctAnswer: q.correctIndex,
                            audioData: q.audioData || null,
                            mappingTopic: q.mappingTopic || '',
                            mappingCriteria: q.mappingCriteria || '',
                            timestamp_seconds: (q.timestamp_seconds !== undefined && q.timestamp_seconds !== null) ? q.timestamp_seconds : null,
                            // ADD-KC-IMAGEGATE (v1.5.115): Map per-question image data.
                            imageUrl: q.imageUrl || '',
                            imageEnabled: q.imageEnabled ? true : false,
                            // ADD-KC-MEDIAPER-Q (v1.5.120): Map per-question video and audio data.
                            questionVideoUrl: q.questionVideoUrl || '',
                            questionVideoEnabled: q.questionVideoEnabled ? true : false,
                            questionAudioUrl: q.questionAudioUrl || '',
                            questionAudioEnabled: q.questionAudioEnabled ? true : false,
                            // ADD-SURVEY-FREETEXT (v1.5.127): Preserve question type.
                            questionType: q.questionType || 'scale'
                        };
                    });
                    
                    // Check if any questions are missing audio (only relevant if voiceover is enabled)
                    var voiceoverOn = $('#voiceover-toggle').is(':checked');
                    var missingAudio = voiceoverOn && quizData.some(function (q) {
                        return !q.audioData || q.audioData.length === 0;
                    });
                    
                    // Show the ready state with existing questions
                    $('#kc-form-section').hide();
                    $('#kc-ready-section').show();
                    var summaryText = quizData.length + ' questions ready.';
                    if (!voiceoverOn) {
                        summaryText += ' Voiceover is disabled.';
                    } else if (missingAudio) {
                        summaryText += ' Some questions are missing voiceover audio.';
                    }
                    $('#ready-summary').text(summaryText);
                    var kcTeacherEta = document.getElementById('kc-teacher-eta');
                    if (kcTeacherEta) {
                        var kcSecPerQ = voiceoverOn ? 120 : 90;
                        var kcTotalSec = quizData.length * kcSecPerQ;
                        var kcMin = Math.ceil(kcTotalSec / 60);
                        var kcTimeStr = kcMin < 1 ? 'Under 1 minute' : (kcMin === 1 ? '~1 minute' : (kcMin < 60 ? '~' + kcMin + ' minutes' : '~' + Math.floor(kcMin / 60) + (Math.floor(kcMin / 60) === 1 ? ' hr ' : ' hrs ') + (kcMin % 60) + ' min'));
                        var kcDetailStr = quizData.length + ' question' + (quizData.length !== 1 ? 's' : '') + (voiceoverOn ? ' with audio explanations' : '');
                        var kcClockSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>';
                        kcTeacherEta.innerHTML = '<div class="kc-eta-banner">' +
                            '<div class="kc-eta-icon-wrap">' + kcClockSvg + '</div>' +
                            '<div class="kc-eta-body">' +
                            '<span class="kc-eta-label">Estimated completion time</span>' +
                            '<span class="kc-eta-time">' + kcTimeStr + '</span>' +
                            '<span class="kc-eta-detail">' + kcDetailStr + '</span>' +
                            '</div></div>';
                    }
                    
                    // Add regenerate-audio button if needed (generate audio is missing)
                    if (!$('#regenerate-audio-btn').length && missingAudio) {
                        var audioBtnHtml = '<button type="button" id="regenerate-audio-btn" class="kc-btn kc-btn-primary" style="margin-left: 10px;">Generate Audio</button>';
                        $('#kc-ready-section .kc-ready-actions').append(audioBtnHtml);
                        $('#regenerate-audio-btn').on('click', function () {
                            regenerateAudio();
                        });
                    }
                } else {
                    console.log('[KC] No existing questions found - showing form');
                }
            },
            error: function (xhr, status, error) {
                console.error('[KC] Check existing questions failed:', status, error);
            }
        });
    }

    function bindEvents() {
        $('#topics-input').on('input', updateStats);
        $('#questions-per-topic').on('change', updateStats);
        $('#country-select').on('change', handleCountryChange);
        $('#voice-gender').on('change', handleGenderChange);
        $('#voiceover-toggle').on('change', function () {
            var isChecked = $(this).is(':checked');
            if (isChecked) {
                $('#voice-settings-section').slideDown(200);
            } else {
                $('#voice-settings-section').slideUp(200);
            }
            updateStats();
        });
        $('#kc-form').on('submit', handleGenerate);
        $('#take-quiz-btn').on('click', startQuiz);
        $('#add-more-questions-btn').on('click', handleAddMoreQuestions);
        $('#edit-questions-btn').on('click', showEditMode);
        $('#download-excel-btn').on('click', downloadExcelMapping);
        $('#save-edits-btn').on('click', saveEdits);
        $('#cancel-edits-btn').on('click', cancelEdits);
        $('#edit-settings-btn').on('click', openSettingsModal);
        $('#close-settings-btn').on('click', closeSettingsModal);
        $('#settings-cancel-btn').on('click', closeSettingsModal);
        $('#ready-regenerate-btn').off('click').on('click', function () { handleRegenerateWithInstructions('ready'); });
        $('#edit-regenerate-btn').off('click').on('click', function () { handleRegenerateWithInstructions('edit'); });
        $('#settings-save-btn').on('click', saveSettings);
        $('#settings-voiceover-toggle').on('change', function () {
            var isChecked = $(this).is(':checked');
            if (isChecked) {
                $('#settings-voice-options').slideDown(200);
            } else {
                $('#settings-voice-options').slideUp(200);
            }
            updateSettingsWarning();
        });
        $('#settings-voice-language').on('change', function () {
            updateSettingsWarning();
        });
        $('#settings-voice-gender').on('change', function () {
            var gender = $(this).val();
            var $style = $('#settings-voice-style');
            $style.empty();
            if (gender === 'female') {
                $style.append($('<option>').val('Aoede').text('Aoede (warm, friendly)'));
                $style.append($('<option>').val('Kore').text('Kore (clear, professional)'));
                $style.append($('<option>').val('Leda').text('Leda (soft, nurturing)'));
                $style.append($('<option>').val('Zephyr').text('Zephyr (energetic, youthful)'));
            } else {
                $style.append($('<option>').val('Puck').text('Puck (friendly, casual)'));
                $style.append($('<option>').val('Charon').text('Charon (deep, authoritative)'));
                $style.append($('<option>').val('Fenrir').text('Fenrir (warm, mature)'));
                $style.append($('<option>').val('Orus').text('Orus (clear, professional)'));
            }
        });
        $(document).on('keydown', function (e) {
            if (e.key === 'Escape' && $('#kc-settings-overlay').is(':visible') && !$('#settings-save-btn').prop('disabled')) {
                closeSettingsModal();
            }
        });
        $('#kc-settings-overlay').on('click', function (e) {
            if (e.target === this) {
                closeSettingsModal();
            }
        });
        $('#check-answer-btn').on('click', checkAnswer);
        $('#next-question-btn').on('click', nextQuestion);
        // Play button removed - voiceover now auto-plays on answer check
        $('#play-audio-btn').hide();
        $('#retake-btn').on('click', retakeQuiz);
        $('#retake-quiz-btn').on('click', retakeQuiz);
        
        // Student buttons - start/continue attempt
        $('#start-attempt-btn').on('click', handleStartAttempt);
        $('#continue-attempt-btn').on('click', handleContinueAttempt);
        
        // User questions toggle.
        $('#user-questions-toggle').on('change', function () {
            var isChecked = $(this).is(':checked');
            if (isChecked) {
                $('#user-questions-fields').slideDown(200);
                // When using own questions, hide topics, criteria and questions per topic
                $('#topics-input').closest('.kc-form-group').slideUp(200);
                $('#criteria-input-group').slideUp(200);
                $('#questions-per-topic-group').slideUp(200);
            } else {
                $('#user-questions-fields').slideUp(200);
                $('#topics-input').closest('.kc-form-group').slideDown(200);
                $('#criteria-input-group').slideDown(200);
                $('#questions-per-topic-group').slideDown(200);
            }
            updateStats();
        });
        
        // User questions input change.
        $('#user-questions-input').on('input', updateStats);
        
        // Paste content toggle.
        $('#paste-content-toggle').on('change', function () {
            var isChecked = $(this).is(':checked');
            if (isChecked) {
                $('#paste-content-fields').slideDown(200);
                $('#topics-input').closest('.kc-form-group').hide();
                $('#criteria-input-group').hide();
                $('#questions-per-topic').closest('.kc-form-group').hide();
                $('#user-questions-toggle').closest('.kc-context-section').hide();
                if (textSources.length === 0) {
                    addTextSource();
                }
            } else {
                $('#paste-content-fields').slideUp(200);
                $('#topics-input').closest('.kc-form-group').show();
                $('#criteria-input-group').show();
                $('#questions-per-topic').closest('.kc-form-group').show();
                $('#user-questions-toggle').closest('.kc-context-section').show();
                textSources = [];
                $('#text-sources-container').empty();
            }
            updateStats();
        });

        $('#add-text-source-btn').on('click', function () {
            addTextSource();
        });

        $('#text-sources-container').on('click', '.kc-text-source-remove', function (e) {
            e.preventDefault();
            var idx = parseInt($(this).data('index'), 10);
            textSources.splice(idx, 1);
            renderTextSources();
            updateStats();
        });

        $('#text-sources-container').on('change', '.kc-text-source-questions', function () {
            var idx = parseInt($(this).data('index'), 10);
            textSources[idx].questionCount = parseInt($(this).val(), 10);
            updateStats();
        });

        $('#text-sources-container').on('input', '.kc-text-source-textarea', function () {
            var idx = parseInt($(this).data('index'), 10);
            textSources[idx].text = $(this).val();
            updateStats();
        });
        
        // Workplace context toggle.
        $('#workplace-context-toggle').on('change', function () {
            var isChecked = $(this).is(':checked');
            if (isChecked) {
                $('#context-fields').slideDown(200);
            } else {
                $('#context-fields').slideUp(200);
            }
        });
        
        // Industry change  -  update current industry and populate the sector dropdown.
        $('#industry-select').on('change', function () {
            currentIndustry = $(this).val();
            var $sectorSelect = $('#industry-sector');
            $sectorSelect.empty().append($('<option>').val('').text('Select sector (optional)...'));
            getIndustrySectors(currentIndustry).forEach(function (s) {
                $sectorSelect.append($('<option>').val(s).text(s));
            });
            $sectorSelect.prop('disabled', !currentIndustry);
        });

        // Job level pills  -  multi-select toggle.
        $('#kc-job-level-pills').on('click', '.kc-level-pill', function () {
            var val = $(this).data('value');
            var idx = selectedKcJobLevels.indexOf(val);
            if (idx > -1) {
                selectedKcJobLevels.splice(idx, 1);
                $(this).removeClass('kc-level-active');
            } else {
                selectedKcJobLevels.push(val);
                $(this).addClass('kc-level-active');
            }
            console.log('[KC] Selected job levels:', selectedKcJobLevels);
        });

        // Job role chips  -  press Enter or comma to add, max 5.
        $('#kc-job-role-input').on('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ',') {
                e.preventDefault();
                var val = $(this).val().trim().replace(/,$/, '');
                if (val && selectedKcJobRoles.indexOf(val) === -1 && selectedKcJobRoles.length < 5) {
                    selectedKcJobRoles.push(val);
                    renderKcJobRoleChips();
                }
                $(this).val('');
            }
        });

        // Education type change - toggle VET/Academic/General.
        $('#education-type-select').on('change', function () {
            var type = $(this).val();
            if (type === 'vet') {
                $('#vet-level-field').show();
                $('#academic-level-field').hide();
                $('#vet-info-card').show();
                $('#academic-info-card').hide();
                $('#general-info-card').hide();
            } else if (type === 'academic') {
                $('#vet-level-field').hide();
                $('#academic-level-field').show();
                $('#vet-info-card').hide();
                $('#academic-info-card').show();
                $('#general-info-card').hide();
            } else {
                // general
                $('#vet-level-field').hide();
                $('#academic-level-field').hide();
                $('#vet-info-card').hide();
                $('#academic-info-card').hide();
                $('#general-info-card').show();
            }
        });
    }

    function renderKcJobRoleChips() {
        var container = document.getElementById('kc-job-role-chips');
        if (!container) return;
        container.innerHTML = selectedKcJobRoles.map(function (role, idx) {
            return '<div class="kc-role-chip">' +
                '<span>' + $('<span>').text(role).html() + '</span>' +
                '<button type="button" class="kc-chip-remove" data-idx="' + idx + '" aria-label="Remove ' + escapeAttr(role) + '">\u00d7</button>' +
                '</div>';
        }).join('');
        $(container).find('.kc-chip-remove').on('click', function () {
            selectedKcJobRoles.splice(parseInt($(this).data('idx'), 10), 1);
            renderKcJobRoleChips();
        });
        var input = document.getElementById('kc-job-role-input');
        if (input) input.disabled = selectedKcJobRoles.length >= 5;
    }

    function handleGenderChange() {
        var gender = $('#voice-gender').val();
        var $voiceStyle = $('#voice-style');
        
        // Clear and rebuild the voice style dropdown based on gender
        $voiceStyle.empty();
        
        if (gender === 'female') {
            // Add female voices
            $voiceStyle.append($('<option>').val('Aoede').text('Aoede (warm, friendly)'));
            $voiceStyle.append($('<option>').val('Kore').text('Kore (clear, professional)'));
            $voiceStyle.append($('<option>').val('Leda').text('Leda (soft, nurturing)'));
            $voiceStyle.append($('<option>').val('Zephyr').text('Zephyr (energetic, youthful)'));
        } else {
            // Add male voices
            $voiceStyle.append($('<option>').val('Puck').text('Puck (friendly, casual)'));
            $voiceStyle.append($('<option>').val('Charon').text('Charon (deep, authoritative)'));
            $voiceStyle.append($('<option>').val('Fenrir').text('Fenrir (warm, mature)'));
            $voiceStyle.append($('<option>').val('Orus').text('Orus (clear, professional)'));
        }
    }

    function fetchCredits() {
        console.log('[KC] fetchCredits called, isTeacher:', config.isTeacher);
        $.ajax({
            url: config.wwwroot + '/mod/aiknowledgecheck/ajax.php',
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'getcredits',
                sesskey: config.sesskey,
                cmid: config.cmid
            },
            success: function (response) {
                console.log('[KC] credits response:', response);
                if (response.ok && response.credits !== undefined) {
                    $('#credits-value').text(response.credits);
                    $('#kc-balance-amount').text(Number(response.credits).toLocaleString());
                    $('#kc-progress-balance').text(Number(response.credits).toLocaleString());
                } else {
                    console.log('[KC] credits error:', response.error || 'Unknown error');
                    $('#credits-value').text('--');
                    $('#kc-balance-amount').text('--');
                    $('#kc-progress-balance').text('--');
                }
            },
            error: function (xhr, status, error) {
                console.log('[KC] credits AJAX error:', status, error);
                $('#credits-value').text('--');
                $('#kc-balance-amount').text('--');
                $('#kc-progress-balance').text('--');
            }
        });
    }

    // -- Industry & Sector Data  -  kept in sync with Content Creator --------------
    var INDUSTRIES = [
        'Aged Care', 'Agriculture', 'Automotive', 'Aviation', 'Building & Construction',
        'Business Services', 'Childcare', 'Community Services', 'Education', 'Electrical',
        'Engineering', 'Finance', 'Food Processing', 'Government', 'Healthcare',
        'Hospitality', 'Information Technology', 'Logistics', 'Manufacturing', 'Mining',
        'Plumbing', 'Retail', 'Security', 'Sport & Recreation', 'Tourism', 'Transport',
        'Utilities', 'Warehousing', 'Other'
    ];
    var INDUSTRY_SUBCATEGORIES = {
        'Aged Care': ['Residential Aged Care','Home Care Services','Dementia Care','Palliative Care','Community Aged Care','Retirement Living','Respite Care','Allied Health in Aged Care'],
        'Agriculture': ['Cropping & Grain','Livestock & Cattle','Dairy Farming','Horticulture','Viticulture & Wine','Aquaculture','Poultry','Shearing & Wool','Agricultural Contracting','Irrigation & Water Management'],
        'Automotive': ['Light Vehicle Mechanical','Heavy Vehicle Mechanical','Auto Electrical','Panel Beating & Spray Painting','Motorcycle Technician','Marine Mechanical','Automotive Parts & Accessories','Vehicle Sales','Tyre Fitting'],
        'Aviation': ['Commercial Aviation','General Aviation','Aircraft Maintenance','Ground Operations','Air Traffic Control','Cabin Crew','Aviation Security','Helicopter Operations'],
        'Building & Construction': ['Residential Construction','Commercial Construction','Civil Construction','Mining Construction','Industrial Construction','High-Rise Construction','Renovation & Refurbishment','Demolition','Scaffolding','Formwork','Concreting','Steel Fixing','Carpentry','Bricklaying','Tiling','Painting & Decorating','Plastering','Roofing','Glazing','Waterproofing'],
        'Business Services': ['Accounting & Bookkeeping','Human Resources','Marketing & Advertising','Legal Services','Consulting','Recruitment','Training & Development','Property Management','Cleaning Services','Security Services'],
        'Childcare': ['Long Day Care','Family Day Care','Outside School Hours Care','Kindergarten/Preschool','Occasional Care','In-Home Care','Special Needs Support','Early Intervention'],
        'Community Services': ['Disability Support','Mental Health Support','Youth Work','Family Services','Homelessness Services','Drug & Alcohol Services','Aboriginal & Torres Strait Islander Services','Refugee & Migrant Services','Domestic Violence Support','Case Management'],
        'Education': ['Primary Education','Secondary Education','Vocational Education (VET)','Higher Education/University','TAFE','Adult Education','Special Education','Early Childhood Education','Online/Distance Education','Education Support','Training Administration','School Administration','Private Training Provider (RTO)'],
        'Electrical': ['Domestic Electrical','Commercial Electrical','Industrial Electrical','Instrumentation','Refrigeration & Air Conditioning','Solar Installation','Data & Communications','Fire Protection Systems','Lift Installation'],
        'Engineering': ['Mechanical Engineering','Civil Engineering','Structural Engineering','Electrical Engineering','Chemical Engineering','Mining Engineering','Environmental Engineering','Project Engineering','Maintenance Engineering'],
        'Finance': ['Banking','Insurance','Financial Planning','Mortgage Broking','Credit & Lending','Superannuation','Investment Management','Payroll','Accounts Payable/Receivable','Auditing'],
        'Food Processing': ['Meat Processing','Seafood Processing','Dairy Processing','Bakery','Beverage Manufacturing','Confectionery','Fruit & Vegetable Processing','Ready Meals & Convenience Foods','Quality Assurance','Food Safety'],
        'Government': ['Local Government','State Government','Federal Government','Emergency Services','Regulatory & Compliance','Policy & Planning','Customer Service','Parks & Recreation','Infrastructure','Community Engagement'],
        'Healthcare': ['Acute Care/Hospital','Primary Care/GP','Allied Health','Mental Health','Community Health','Dental','Pharmacy','Pathology','Radiology','Emergency Services','Surgical','Rehabilitation','Infection Control','Aged Care Nursing','Midwifery','Disability Health','Aboriginal Health'],
        'Hospitality': ['Hotels & Accommodation','Restaurants & Cafes','Bars & Pubs','Catering','Events & Functions','Fast Food & Quick Service','Clubs & Gaming','Commercial Cookery','Patisserie','Front Office','Housekeeping'],
        'Information Technology': ['Software Development','Network Administration','Cybersecurity','Cloud Computing','Database Administration','IT Support/Help Desk','Web Development','Data Analytics','Systems Administration','IT Project Management'],
        'Logistics': ['Supply Chain Management','Freight Forwarding','Customs & Border','Inventory Management','Distribution','Third-Party Logistics (3PL)','Last Mile Delivery','Cold Chain Logistics','Dangerous Goods'],
        'Manufacturing': ['Food & Beverage Manufacturing','Pharmaceutical Manufacturing','Chemical Manufacturing','Metal Fabrication','Plastics & Rubber','Textiles','Furniture Manufacturing','Electronics Manufacturing','Printing','Packaging','Process Manufacturing'],
        'Mining': ['Open Cut Mining','Underground Mining','Coal Mining','Iron Ore','Gold Mining','Mineral Processing','Exploration','Drilling','Mine Site Services','Tailings Management','Mine Rehabilitation'],
        'Plumbing': ['Domestic Plumbing','Commercial Plumbing','Industrial Plumbing','Gas Fitting','Roofing & Drainage','Fire Protection Plumbing','Irrigation','Water Treatment','Mechanical Services'],
        'Retail': ['Supermarkets & Grocery','Fashion & Apparel','Electronics & Technology','Hardware & Building','Pharmacy Retail','Furniture & Homewares','Automotive Retail','Sporting Goods','Online/E-commerce','Luxury Retail'],
        'Security': ['Static Security','Mobile Patrol','Event Security','Close Protection','Loss Prevention','Corporate Security','Cash in Transit','CCTV & Monitoring','Access Control','Cybersecurity Operations'],
        'Sport & Recreation': ['Fitness & Personal Training','Aquatics','Outdoor Recreation','Sports Coaching','Sports Administration','Community Recreation','Event Management','Golf & Turf Management','Sports Medicine Support'],
        'Tourism': ['Travel Agencies','Tour Operations','Attractions & Theme Parks','Eco-Tourism','Adventure Tourism','Cultural Tourism','Cruise Operations','Tourism Marketing','Visitor Information Services','Indigenous Tourism'],
        'Transport': ['Road Transport','Rail Transport','Maritime Transport','Air Transport','Public Transport','Taxi & Rideshare','Courier Services','Bus Operations','Heavy Vehicle Operations','Transport Administration'],
        'Utilities': ['Electricity Generation','Electricity Distribution','Gas Distribution','Water Supply','Wastewater Treatment','Renewable Energy','Smart Grid','Meter Reading','Network Maintenance'],
        'Warehousing': ['General Warehousing','Cold Storage','Distribution Centres','Cross-Docking','Hazardous Goods Storage','Automated Warehousing','Order Fulfillment','Returns Processing','Inventory Control'],
        'Other': ['General Industry','Cross-Industry','Emerging Industry']
    };
    function getIndustrySectors(industry) { return INDUSTRY_SUBCATEGORIES[industry] || []; }
    // ----------------------------------------------------------------------------

    function fetchIndustries() {
        var $select = $('#industry-select');
        var $sectorSelect = $('#industry-sector');
        INDUSTRIES.forEach(function (ind) {
            $select.append($('<option>').val(ind).text(ind));
        });
        currentIndustry = $select.val() || '';
    }

    function handleCountryChange() {
        var country = $('#country-select').val();
        var $stateSelect = $('#state-select');
        
        $stateSelect.empty().append($('<option>').val('').text('Select state/region...'));
        
        if (country && countryStates[country]) {
            countryStates[country].forEach(function (state) {
                $stateSelect.append($('<option>').val(state.value).text(state.label));
            });
            $stateSelect.prop('disabled', countryStates[country].length === 0);
        } else {
            $stateSelect.prop('disabled', true);
        }
    }

    function addTextSource() {
        if (textSources.length >= 10) {
            alert('Maximum 10 text sources allowed.');
            return;
        }
        textSources.push({
            text: '',
            questionCount: 10
        });
        renderTextSources();
    }

    function renderTextSources() {
        var $container = $('#text-sources-container');
        $container.empty();

        for (var i = 0; i < textSources.length; i++) {
            var source = textSources[i];
            var sourceNum = i + 1;
            var charCount = source.text ? source.text.length : 0;
            var html = '<div class="kc-text-source-item">' +
                '<div class="kc-text-source-header">' +
                    '<span class="kc-text-source-label">Text source ' + sourceNum + '</span>' +
                    '<div class="kc-text-source-controls">' +
                        '<select class="kc-select kc-text-source-questions" data-index="' + i + '">' +
                            (function () {
                                var opts = '';
                                for (var q = 1; q <= 30; q++) {
                                    opts += '<option value="' + q + '"' + (source.questionCount === q ? ' selected' : '') + '>' + q + ' Qs</option>';
                                }
                                return opts;
                            })() +
                        '</select>' +
                        (textSources.length > 1 ? '<button type="button" class="kc-text-source-remove" data-index="' + i + '" title="Remove">' +
                            '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
                                '<line x1="18" y1="6" x2="6" y2="18"/>' +
                                '<line x1="6" y1="6" x2="18" y2="18"/>' +
                            '</svg>' +
                        '</button>' : '') +
                    '</div>' +
                '</div>' +
                '<textarea class="kc-text-source-textarea" data-index="' + i + '" rows="6" placeholder="Paste your text content here...">' + escapeHtml(source.text) + '</textarea>' +
                '<div class="kc-text-source-footer">' +
                    '<span class="kc-text-source-charcount">' + charCount.toLocaleString() + ' characters</span>' +
                    '<span class="kc-text-source-limit">Max 50,000</span>' +
                '</div>' +
            '</div>';
            $container.append(html);
        }
    }

    function updateStats() {
        var useTextSources = $('#paste-content-toggle').is(':checked');
        var useOwnQuestions = $('#user-questions-toggle').is(':checked');
        var totalQuestions = 0;
        var topicsCount = 0;
        
        if (useTextSources && textSources.length > 0) {
            totalQuestions = 0;
            for (var p = 0; p < textSources.length; p++) {
                if (textSources[p].text && textSources[p].text.trim().length > 0) {
                    totalQuestions += textSources[p].questionCount;
                    topicsCount++;
                }
            }
        } else if (useOwnQuestions) {
            var userQuestions = $('#user-questions-input').val().trim().split('\n').filter(function (q) { return q.trim(); });
            totalQuestions = userQuestions.length;
            topicsCount = totalQuestions;
        } else {
            var topics = $('#topics-input').val().trim().split('\n').filter(function (t) { return t.trim(); });
            var questionsPerTopic = parseInt($('#questions-per-topic').val(), 10);
            totalQuestions = topics.length * questionsPerTopic;
            topicsCount = topics.length;
        }
        
        var voiceoverOn = $('#voiceover-toggle').is(':checked');
        var creditsPerQuestion = voiceoverOn ? 2 : 1;
        var credits = totalQuestions * creditsPerQuestion;
        var audAmount = (credits / 10).toFixed(2);
        
        var formulaHtml = '<strong>' + totalQuestions + ' questions</strong> x ' + creditsPerQuestion + ' credit' + (creditsPerQuestion > 1 ? 's' : '') + ' = <strong>' + credits.toLocaleString() + ' credits</strong> ($' + audAmount + ' AUD)';
        $('#kc-credit-formula').html(formulaHtml);
        $('#kc-progress-credit-formula').html(formulaHtml);
        
        if (totalQuestions > 0) {
            $('#preview-stats').show();
            $('#generate-btn').prop('disabled', false);
        } else {
            $('#preview-stats').hide();
            $('#generate-btn').prop('disabled', true);
        }
    }

    function handleGenerate(e) {
        e.preventDefault();
        
        var useTextSources = $('#paste-content-toggle').is(':checked');
        var useOwnQuestions = $('#user-questions-toggle').is(':checked');
        var topics = '';
        var userQuestions = '';
        
        if (useTextSources) {
            var validSources = textSources.filter(function (s) { return s.text && s.text.trim().length > 0; });
            if (validSources.length === 0) {
                alert('Please add at least one text source with content.');
                return;
            }
            topics = 'Pasted content';
        } else if (useOwnQuestions) {
            userQuestions = $('#user-questions-input').val().trim();
            if (!userQuestions) {
                alert('Please enter at least one question.');
                return;
            }
            topics = 'User-provided questions';
        } else {
            topics = $('#topics-input').val().trim();
            if (!topics) {
                alert('Please enter at least one topic.');
                return;
            }
        }
        
        // Get workplace context if enabled.
        var workplaceContextEnabled = $('#workplace-context-toggle').is(':checked') ? 1 : 0;
        
        // Get education settings.
        var educationType = $('#education-type-select').val();
        var vetLevel = educationType === 'vet' ? $('#vet-level-select').val() : '';
        var academicLevel = educationType === 'academic' ? $('#academic-level-select').val() : '';
        
        $('#kc-form-section').hide();
        $('#kc-progress-section').show();
        $('#progress-fill').css('width', '5%');
        $('#progress-message').text('Starting generation...');
        
        var data = {
            action: 'generate',
            sesskey: config.sesskey,
            cmid: config.cmid,
            topics: topics,
            questionsPerTopic: useOwnQuestions ? 1 : $('#questions-per-topic').val(),
            useOwnQuestions: useOwnQuestions ? 1 : 0,
            userQuestions: userQuestions,
            useTextSources: useTextSources ? 1 : 0,
            textSources: '',
            workplaceContextEnabled: workplaceContextEnabled,
            country: workplaceContextEnabled ? ($('#country-select').val() || '') : '',
            state: workplaceContextEnabled ? ($('#state-select').val() || '') : '',
            industry: workplaceContextEnabled ? ($('#industry-select').val() || '') : '',
            industryDetails: workplaceContextEnabled ? ($('#industry-sector').val() || '') : '',
            jobLevel: workplaceContextEnabled ? selectedKcJobLevels.join(', ') : '',
            jobTitle: workplaceContextEnabled ? selectedKcJobRoles.join(', ') : '',
            educationType: educationType,
            vetLevel: vetLevel,
            academicLevel: academicLevel,
            extraInstructions: $('#extra-instructions').val() || '',
            voiceoverEnabled: $('#voiceover-toggle').is(':checked') ? 1 : 0,
            voiceLanguage: $('#voice-language').val(),
            voiceGender: $('#voice-gender').val(),
            voiceId: $('#voice-style').val(),
            // ADD-SURVEY-MODE (v1.5.126): Forward survey params to SaaS via ajax.php.
            surveyMode: config.surveyMode ? 1 : 0,
            // FIX-KC-SURVEY-SCALE (v1.5.140): read the scale from the activity config, not from
            // a '#survey-scale' element. The teacher picks the Response Scale in the activity
            // settings form (mod_form.php); no such element has ever existed on the view page,
            // so .val() always returned undefined and the '|| likert5agree' fallback fired on
            // every generation. Every scale other than the first silently produced Agreement
            // questions. view.php already supplies the real value as config.surveyScale.
            surveyScale: config.surveyMode ? (config.surveyScale || 'likert5agree') : 'likert5agree',
            // ADD-SURVEY-FREETEXT (v1.5.127): Forward free-text questions (one per line).
            freetextQuestions: config.surveyMode
                ? JSON.stringify(($('#freetext-questions-input').val() || '').split('\n').map(function (s) { return s.trim(); }).filter(function (s) { return s.length > 0; }))
                : '[]'
        };

        if (useTextSources) {
            var validSources = textSources.filter(function (s) { return s.text && s.text.trim().length > 0; });
            data.textSources = JSON.stringify(validSources.map(function (s) {
                return { text: s.text.trim().substring(0, 50000), questionCount: s.questionCount };
            }));
            console.log('[KC] Text sources mode - sending through Moodle ajax.php');
            console.log('[KC] Text sources:', validSources.length);
            validSources.forEach(function (s, i) {
                console.log('[KC] Source ' + (i+1) + ': ' + s.text.length + ' chars, questions: ' + s.questionCount);
            });
        } else {
            console.log('[KC] Topics mode - sending through Moodle ajax.php');
            console.log('[KC] Topics: "' + topics.substring(0, 100) + '", questionsPerTopic: ' + (useOwnQuestions ? 1 : $('#questions-per-topic').val()));
        }

        $.ajax({
            url: config.wwwroot + '/mod/aiknowledgecheck/ajax.php',
            method: 'POST',
            dataType: 'json',
            data: data,
            timeout: useTextSources ? 120000 : 60000,
            success: handleGenerateSuccess,
            error: handleGenerateError
        });
    }
    
    function handleGenerateSuccess(response) {
        console.log('[KC] Generate response:', JSON.stringify(response));
        if (response.ok && response.jobId) {
            console.log('[KC] Job started: ' + response.jobId + ', credits: ' + response.creditsRequired + ', questions: ' + response.totalQuestions);
            currentJobId = response.jobId;
            startStatusPolling();
        } else if (response.error === 'INSUFFICIENT_CREDITS') {
            console.warn('[KC] Insufficient credits - has: ' + response.credits + ', needs: ' + response.required);
            alert('Insufficient credits. Please purchase more at lms-labs.com');
            $('#kc-progress-section').hide();
            $('#kc-form-section').show();
        } else {
            console.error('[KC] Generation failed:', response.error || 'Unknown error');
            alert(response.error || 'Failed to start generation');
            $('#kc-progress-section').hide();
            $('#kc-form-section').show();
        }
    }
    
    function handleGenerateError(xhr, status, error) {
        console.error('[KC] AJAX error - status:', status, 'error:', error, 'HTTP:', xhr.status, 'response:', xhr.responseText);
        var msg = 'Request failed. Please try again.';
        if (status === 'timeout') {
            msg = 'Request timed out. The PDF may be too large. Please try a smaller file.';
        } else if (xhr.status === 413) {
            msg = 'The PDF file is too large for the server to process. Please try a smaller file.';
        } else if (xhr.status === 0) {
            msg = 'Could not connect to the server. Please check your internet connection.';
        } else if (xhr.responseText) {
            try {
                var errResp = JSON.parse(xhr.responseText);
                if (errResp.error) {
                    msg = errResp.error;
                }
            } catch(e) {
                console.error('[KC] Could not parse error response:', xhr.responseText.substring(0, 500));
            }
        }
        if (isAddingMore && existingQuizData) {
            quizData = existingQuizData;
            existingQuizData = null;
            isAddingMore = false;
            $('#kc-add-more-banner').hide();
            alert(msg + '\n\nYour existing questions have been preserved.');
            $('#kc-progress-section').hide();
            showQuizReady();
        } else {
            alert(msg);
            $('#kc-progress-section').hide();
            $('#kc-form-section').show();
        }
    }

    function startStatusPolling() {
        statusPollFailures = 0;
        statusPollingInterval = setInterval(checkStatus, 2000);
    }

    function checkStatus() {
        $.ajax({
            url: config.wwwroot + '/mod/aiknowledgecheck/ajax.php',
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'status',
                sesskey: config.sesskey,
                cmid: config.cmid,
                jobId: currentJobId
            },
            success: function (response) {
                statusPollFailures = 0;
                if (response.ok) {
                    $('#progress-fill').css('width', response.progress + '%');
                    $('#progress-message').text(response.progressMessage);
                    
                    if (response.status === 'completed') {
                        clearInterval(statusPollingInterval);
                        var newQuestions = response.questions || [];

                        // FIX-KC-ZERO-Q-GUARD: if the server completes but returns an empty
                        // question list (e.g. large audio payload caused PHP json_encode to fail
                        // silently before the streaming fix), show a meaningful error instead
                        // of displaying "0 questions generated with voiceover!".
                        if (newQuestions.length === 0) {
                            console.error('[KC] Job completed but returned 0 questions  -  possible server-side error. Check server logs.');
                            if (isAddingMore && existingQuizData) {
                                quizData = existingQuizData;
                                existingQuizData = null;
                                isAddingMore = false;
                                $('#kc-add-more-banner').hide();
                                alert('Generation completed but returned 0 questions. Your existing questions have been preserved. Please try again.');
                                $('#kc-progress-section').hide();
                                showQuizReady();
                            } else {
                                alert('Generation completed but returned 0 questions. Please try again.');
                                $('#kc-progress-section').hide();
                                $('#kc-form-section').show();
                            }
                            return;
                        }

                        // Tag each new question with its topic and criteria from the form
                        // so they are persisted to DB and appear in the Excel mapping.
                        var topicLines    = $('#topics-input').val().trim().split('\n').filter(function (l) { return l.trim(); });
                        var criteriaLines = $('#criteria-input').val().trim().split('\n');
                        var qpt = parseInt($('#questions-per-topic').val(), 10) || 1;
                        newQuestions.forEach(function (q, idx) {
                            var topicIdx = Math.floor(idx / qpt);
                            if (!q.mappingTopic)    { q.mappingTopic    = (topicLines[topicIdx]    || '').trim(); }
                            if (!q.mappingCriteria) { q.mappingCriteria = (criteriaLines[topicIdx]  || '').trim(); }
                        });

                        if (isAddingMore && existingQuizData) {
                            console.log('[KC] Add More  -  appending ' + newQuestions.length + ' new questions to ' + existingQuizData.length + ' existing');
                            quizData = existingQuizData.concat(newQuestions);
                            existingQuizData = null;
                            isAddingMore = false;
                            $('#kc-add-more-banner').hide();
                        } else {
                            quizData = newQuestions;
                        }

                        showQuizReady();
                    } else if (response.status === 'failed') {
                        clearInterval(statusPollingInterval);
                        if (isAddingMore && existingQuizData) {
                            quizData = existingQuizData;
                            existingQuizData = null;
                            isAddingMore = false;
                            $('#kc-add-more-banner').hide();
                            alert((response.error || 'Generation failed') + '\n\nYour existing questions have been preserved.');
                            $('#kc-progress-section').hide();
                            showQuizReady();
                        } else {
                            alert(response.error || 'Generation failed');
                            $('#kc-progress-section').hide();
                            $('#kc-form-section').show();
                        }
                    }
                }
            },
            error: function (xhr, status, error) {
                statusPollFailures++;
                console.error('Status check failed (attempt ' + statusPollFailures + '/' + MAX_POLL_FAILURES + '):', status, error);
                if (statusPollFailures >= MAX_POLL_FAILURES) {
                    clearInterval(statusPollingInterval);
                    console.error('[KC] Status polling stopped after ' + MAX_POLL_FAILURES + ' consecutive failures');
                    if (isAddingMore && existingQuizData) {
                        quizData = existingQuizData;
                        existingQuizData = null;
                        isAddingMore = false;
                        $('#kc-add-more-banner').hide();
                        alert('Lost connection to the server. Your existing questions have been preserved.');
                        $('#kc-progress-section').hide();
                        showQuizReady();
                    } else {
                        alert('Lost connection to the server. Your questions may still be generating - please refresh the page to check.');
                        $('#kc-progress-section').hide();
                        $('#kc-form-section').show();
                    }
                }
            }
        });
    }

    function showQuizReady() {
        // FIX-KC-GUARD-SHOWQUIZREADY: Only persist to DB when there are actually questions.
        // Calling saveQuestionsToDatabase() with an empty quizData would DELETE all existing
        // DB questions (the savequestions action does DELETE then INSERT).
        if (quizData && quizData.length > 0) {
            saveQuestionsToDatabase();
        } else {
            console.warn('[KC] showQuizReady called with empty quizData  -  skipping DB save to protect existing questions.');
        }
        
        $('#kc-progress-section').hide();
        $('#kc-ready-section').show();
        var voiceoverOn = $('#voiceover-toggle').is(':checked');
        var qCount = quizData ? quizData.length : 0;
        if (voiceoverOn) {
            $('#ready-summary').text(qCount + ' questions generated with voiceover!');
        } else {
            $('#ready-summary').text(qCount + ' questions generated successfully!');
        }
        var initialInstructions = $('#extra-instructions').val() || '';
        $('#ready-extra-instructions').val(initialInstructions);
        $('#edit-extra-instructions').val(initialInstructions);
        updateRegenCountDisplay();
        fetchCredits();
    }
    
    function handleAddMoreQuestions() {
        if (!quizData || quizData.length === 0) {
            alert('No existing questions found. Please generate questions first.');
            return;
        }
        console.log('[KC] Add More Questions  -  preserving ' + quizData.length + ' existing questions');
        existingQuizData = quizData.slice();
        isAddingMore = true;
        $('#kc-ready-section').hide();
        $('#kc-form-section').show();
        var $banner = $('#kc-add-more-banner');
        if (!$banner.length) {
            var bannerHtml = '<div id="kc-add-more-banner" class="kc-add-more-info">' +
                '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
                '<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>' +
                '</svg>' +
                '<span>Adding to your existing <strong>' + existingQuizData.length + ' question' + (existingQuizData.length !== 1 ? 's' : '') + '</strong>. New questions will be appended to the current set.</span>' +
                '</div>';
            $('#kc-form').prepend(bannerHtml);
        } else {
            $banner.find('span').html('Adding to your existing <strong>' + existingQuizData.length + ' question' + (existingQuizData.length !== 1 ? 's' : '') + '</strong>. New questions will be appended to the current set.');
            $banner.show();
        }
    }

    // FIX-KC-SAVE-SILENT: v1.5.68  -  show visible alert to teacher when save fails,
    // so they know to retry rather than thinking "Quiz Ready!" means questions are live.
    function saveQuestionsToDatabase() {
        // Transform quizData to match database schema
        // FIX-KC-SURVEY-SAVE (v1.5.138): three faults lived in this one mapping.
        //
        // 1. It read q.options[n] as a STRING. Freshly generated questions arrive straight from
        //    the generation service through the 'status' passthrough, which emits options as
        //    {text, explanation} OBJECTS -- the same shape this file sends back in the
        //    regenerate payload, described there as "the API's expected input format". So
        //    { text: q.options[0] } nested an object inside .text, PHP received an array where
        //    it expected a string, and the insert died inside mysqli with
        //    "real_escape_string(): Argument #1 ($string) must be of type string, array given".
        //
        // 2. It hardcoded exactly four options. A five-point survey scale silently lost its
        //    fifth, and a freetext question -- which has none -- gained four empty ones.
        //
        // 3. It never sent questionType at all, so the server fell back to 'scale' and every
        //    freetext question was stored as a scale question.
        //
        // normaliseOption() accepts either shape, so generated, reloaded and hand-edited
        // questions all save the same way.
        function normaliseOption(opt, fallbackExplanation) {
            if (opt === null || opt === undefined) {
                return null;
            }
            if (typeof opt === 'object') {
                return {
                    text: typeof opt.text === 'string' ? opt.text : String(opt.text || ''),
                    explanation: typeof opt.explanation === 'string'
                        ? opt.explanation
                        : (fallbackExplanation || '')
                };
            }
            return { text: String(opt), explanation: fallbackExplanation || '' };
        }

        var questionsForDb = quizData.map(function (q) {
            // Debug: log audio data being saved
            if (q.audioData) {
                console.log('[KC] Saving question with audio data:', Object.keys(q.audioData).length, 'tracks');
            } else {
                console.log('[KC] Saving question without audio data');
            }

            var isFreetext = q.questionType === 'freetext';
            var rawOptions = (!isFreetext && Array.isArray(q.options)) ? q.options : [];
            var options = [];
            for (var oi = 0; oi < rawOptions.length; oi++) {
                var normalised = normaliseOption(
                    rawOptions[oi],
                    (q.explanations && q.explanations[oi]) ? q.explanations[oi] : ''
                );
                if (normalised !== null) {
                    options.push(normalised);
                }
            }

            return {
                question: typeof q.question === 'string' ? q.question : String(q.question || ''),
                options: options,
                questionType: isFreetext ? 'freetext' : 'scale',
                correctIndex: (typeof q.correctAnswer === 'number') ? q.correctAnswer : 0,
                audioData: q.audioData || null,
                mappingTopic: q.mappingTopic || '',
                mappingCriteria: q.mappingCriteria || '',
                timestamp_seconds: (q.timestamp_seconds !== undefined && q.timestamp_seconds !== null) ? q.timestamp_seconds : null,
                // ADD-KC-IMAGEGATE (v1.5.115): Include per-question image data.
                imageUrl: q.imageUrl || '',
                imageEnabled: q.imageEnabled ? 1 : 0,
                // ADD-KC-MEDIAPER-Q (v1.5.120): Include per-question video and audio data.
                questionVideoUrl: q.questionVideoUrl || '',
                questionVideoEnabled: q.questionVideoEnabled ? 1 : 0,
                questionAudioUrl: q.questionAudioUrl || '',
                questionAudioEnabled: q.questionAudioEnabled ? 1 : 0
            };
        });
        
        $.ajax({
            url: config.wwwroot + '/mod/aiknowledgecheck/ajax.php',
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'savequestions',
                sesskey: config.sesskey,
                cmid: config.cmid,
                questions: JSON.stringify(questionsForDb),
                voiceoverEnabled: $('#voiceover-toggle').is(':checked') ? 1 : 0,
                voiceLanguage: $('#voice-language').val() || '',
                voiceGender: $('#voice-gender').val() || '',
                voiceStyle: $('#voice-style').val() || ''
            },
            success: function (response) {
                if (response.ok) {
                    console.log('[KC] Questions saved to database:', response.saved);
                } else {
                    console.error('[KC] Failed to save questions:', response.error);
                    alert('Warning: Questions could not be saved to Moodle. Students will not be able to see this quiz until the save succeeds.\n\nReason: ' + (response.error || 'Unknown error') + '\n\nPlease refresh the page and try generating again, or contact your administrator.');
                }
            },
            error: function (xhr, status, error) {
                console.error('[KC] Save questions request failed:', status, error);
                alert('Warning: The connection to Moodle was lost while saving questions. Students will not be able to see this quiz.\n\nPlease refresh the page and regenerate your questions, or check your network connection.');
            }
        });
    }
    
    // Regenerate audio for existing questions (FREE - no credit cost)
    function regenerateAudio() {
        console.log('[KC] Regenerating audio for existing questions');
        
        // Get current voice settings
        var voiceLanguage = $('#voice-language').val() || 'en-AU';
        var voiceId = $('#voice-style').val() || 'Aoede';
        
        // Show progress
        $('#regenerate-audio-btn').prop('disabled', true).text('Generating Audio...');
        
        // Prepare questions data for the API
        var questionsForApi = quizData.map(function (q) {
            return {
                id: q.id,
                question: q.question,
                options: q.options,
                explanations: q.explanations,
                correctAnswer: q.correctAnswer
            };
        });
        
        $.ajax({
            url: config.wwwroot + '/mod/aiknowledgecheck/ajax.php',
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'regenerateaudio',
                sesskey: config.sesskey,
                cmid: config.cmid,
                questions: JSON.stringify(questionsForApi),
                voiceLanguage: voiceLanguage,
                voiceId: voiceId
            },
            timeout: 120000,
            success: function (response) {
                if (response.ok && response.questions) {
                    console.log('[KC] Audio regenerated successfully for', response.questions.length, 'questions');
                    
                    // Update quizData with new audio
                    for (var i = 0; i < response.questions.length; i++) {
                        if (quizData[i] && response.questions[i].audioData) {
                            quizData[i].audioData = response.questions[i].audioData;
                            console.log('[KC] Question', i, 'now has', response.questions[i].audioData.length, 'audio tracks');
                        }
                    }
                    
                    // Save updated questions to database
                    saveQuestionsToDatabase();
                    
                    // Update UI
                    $('#regenerate-audio-btn').remove();
                    $('#ready-summary').text(quizData.length + ' questions ready with voiceover audio!');
                    alert('Audio generated successfully! Students will now hear voiceover explanations.');
                } else {
                    console.error('[KC] Audio regeneration failed:', response.error);
                    alert('Failed to generate audio: ' + (response.error || 'Unknown error'));
                    $('#regenerate-audio-btn').prop('disabled', false).text('Generate Audio');
                }
            },
            error: function (xhr, status, error) {
                console.error('[KC] Audio regeneration request failed:', status, error);
                alert('Failed to generate audio. Please try again.');
                $('#regenerate-audio-btn').prop('disabled', false).text('Generate Audio');
            }
        });
    }
    
    // ==========================================
    // STUDENT FUNCTIONS - Start/Continue Attempt
    // ==========================================
    
    function handleStartAttempt() {
        console.log('[KC] Starting new attempt');
        pendingSaves = 0;
        pendingFinishAttempt = false;
        $('#start-attempt-btn').prop('disabled', true).text('Loading...');
        
        $.ajax({
            url: config.wwwroot + '/mod/aiknowledgecheck/ajax.php',
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'startattempt',
                sesskey: config.sesskey,
                cmid: config.cmid
            },
            success: function (response) {
                if (response.ok) {
                    currentAttemptId = response.attemptid;
                    console.log('[KC] Attempt started:', currentAttemptId);
                    loadQuestionsFromDatabase();
                } else {
                    alert(response.error || 'Failed to start attempt');
                    $('#start-attempt-btn').prop('disabled', false).text('Start Quiz');
                }
            },
            error: function (xhr, status, error) {
                console.error('[KC] Start attempt failed:', status, error);
                alert('Failed to start quiz. Please try again.');
                $('#start-attempt-btn').prop('disabled', false).text('Start Quiz');
            }
        });
    }
    
    function handleContinueAttempt() {
        console.log('[KC] Continuing attempt');
        var attemptId = $('#continue-attempt-btn').data('attemptid');
        $('#continue-attempt-btn').prop('disabled', true).text('Loading...');

        // Call startattempt to get the authoritative server-side progress.
        // This returns the existing in-progress attempt with the answers dict.
        // We derive resumeFromIndex from the number of answered questions, which
        // correctly reflects shuffled position regardless of original questionnumber.
        $.ajax({
            url: config.wwwroot + '/mod/aiknowledgecheck/ajax.php',
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'startattempt',
                sesskey: config.sesskey,
                cmid: config.cmid
            },
            success: function (response) {
                if (response.ok) {
                    currentAttemptId = response.attemptid;
                    console.log('[KC] Continue attempt ID confirmed:', currentAttemptId, 'resumed:', response.resumed);

                    // Determine resume position: answers.length = number of questions
                    // already answered, which is the correct 0-based index to restart from.
                    var serverAnswerCount = (response.answers && typeof response.answers === 'object')
                        ? Object.keys(response.answers).length : 0;

                    // Also check localStorage in case the student advanced past the last save point.
                    var storageKey = 'kc_progress_' + config.cmid + '_' + currentAttemptId;
                    var saved = localStorage.getItem(storageKey);
                    var localIdx = (saved !== null) ? parseInt(saved, 10) : 0;
                    if (isNaN(localIdx) || localIdx < 0) { localIdx = 0; }

                    // Use whichever is further along.
                    resumeFromIndex = Math.max(serverAnswerCount, localIdx);
                    console.log('[KC] Resume index  -  server answers:', serverAnswerCount, 'localStorage:', localIdx, 'using:', resumeFromIndex);

                    // BUG-SCORE-RESUME fix: save the server's answers dict so
                    // startStudentQuiz() can reconstruct the score from previously
                    // answered questions instead of always resetting score to 0.
                    resumeAnswers = (response.answers && typeof response.answers === 'object')
                        ? response.answers : null;

                    loadQuestionsFromDatabase();
                } else {
                    console.error('[KC] Continue attempt failed:', response.error);
                    alert(response.error || 'Failed to continue attempt. Please reload the page.');
                    $('#continue-attempt-btn').prop('disabled', false).text('Continue Attempt');
                }
            },
            error: function (xhr, status, error) {
                console.error('[KC] Continue attempt AJAX failed:', status, error);
                alert('Failed to continue attempt. Please try again.');
                $('#continue-attempt-btn').prop('disabled', false).text('Continue Attempt');
            }
        });
    }
    
    // Fisher-Yates shuffle algorithm - returns shuffled indices
    function getShuffledIndices(length) {
        var indices = [];
        for (var i = 0; i < length; i++) {
            indices.push(i);
        }
        // Fisher-Yates shuffle
        for (var i = length - 1; i > 0; i--) {
            var j = Math.floor(Math.random() * (i + 1));
            var temp = indices[i];
            indices[i] = indices[j];
            indices[j] = temp;
        }
        return indices;
    }
    
    function loadQuestionsFromDatabase() {
        console.log('[KC] Loading questions from database');
        
        $.ajax({
            url: config.wwwroot + '/mod/aiknowledgecheck/ajax.php',
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'getquestions',
                sesskey: config.sesskey,
                cmid: config.cmid
            },
            success: function (response) {
                if (response.ok && response.questions && response.questions.length > 0) {
                    console.log('[KC] Loaded questions:', response.questions.length);
                    
                    // Transform database format to quiz format with shuffled answers
                    quizData = response.questions.map(function (q) {
                        // Debug: log audio data availability
                        if (q.audioData) {
                            console.log('[KC] Question', q.id, 'has audio data for', Object.keys(q.audioData).length, 'answers');
                        } else {
                            console.log('[KC] Question', q.id, 'has no audio data');
                        }

                        // ADD-SURVEY-FREETEXT (v1.5.127): Freetext questions have no options — bypass shuffle.
                        if (q.questionType === 'freetext') {
                            return {
                                id: q.id,
                                question: q.question,
                                options: [],
                                explanations: [],
                                correctAnswer: 0,
                                originalCorrectIndex: 0,
                                shuffledToOriginal: [],
                                audioData: null,
                                timestamp_seconds: null,
                                imageUrl: '',
                                imageEnabled: false,
                                questionVideoUrl: '',
                                questionVideoEnabled: false,
                                questionAudioUrl: '',
                                questionAudioEnabled: false,
                                questionType: 'freetext'
                            };
                        }
                        
                        // Quiz answers are shuffled. Survey scales retain their authored order
                        // (for example Strongly Agree through Strongly Disagree), and may have
                        // two, three, four, or five options.
                        var optionCount = Array.isArray(q.options) ? q.options.length : 0;
                        var shuffledIndices = [];
                        if (config.surveyMode) {
                            for (var optionIndex = 0; optionIndex < optionCount; optionIndex++) {
                                shuffledIndices.push(optionIndex);
                            }
                        } else {
                            shuffledIndices = getShuffledIndices(optionCount);
                        }
                        
                        // Build shuffled arrays and mapping from shuffled position to original
                        var shuffledOptions = [];
                        var shuffledExplanations = [];
                        var shuffledAudioData = q.audioData ? [] : null;
                        var shuffledToOriginal = []; // Maps shuffled index -> original index
                        // SECURITY (C2): students receive correctIndex === null. Keep correctAnswer
                        // null in that case so checkAnswer resolves it from the server at check time
                        // (rather than defaulting to 0 and revealing/marking the wrong option).
                        var answerWithheld = (q.correctIndex === null || q.correctIndex === undefined);
                        var newCorrectIndex = answerWithheld ? null : 0;

                        for (var i = 0; i < optionCount; i++) {
                            var origIndex = shuffledIndices[i];
                            shuffledOptions.push(q.options[origIndex].text);
                            shuffledExplanations.push(q.options[origIndex].explanation);
                            shuffledToOriginal.push(origIndex); // Store original index for this position
                            if (shuffledAudioData && q.audioData[origIndex]) {
                                shuffledAudioData.push(q.audioData[origIndex]);
                            } else if (shuffledAudioData) {
                                shuffledAudioData.push(null);
                            }
                            // Track where the correct answer ended up (only when it was provided).
                            if (!answerWithheld && origIndex === q.correctIndex) {
                                newCorrectIndex = i;
                            }
                        }

                        return {
                            id: q.id,
                            question: q.question,
                            options: shuffledOptions,
                            explanations: shuffledExplanations,
                            correctAnswer: newCorrectIndex,
                            originalCorrectIndex: q.correctIndex, // Keep original for database
                            shuffledToOriginal: shuffledToOriginal, // Mapping for answer submission
                            audioData: shuffledAudioData,
                            timestamp_seconds: (q.timestamp_seconds !== undefined && q.timestamp_seconds !== null) ? q.timestamp_seconds : null,
                            // ADD-KC-IMAGEGATE (v1.5.115): Map per-question image data (not shuffled — always tied to question).
                            imageUrl: q.imageUrl || '',
                            imageEnabled: q.imageEnabled ? true : false,
                            // ADD-KC-MEDIAPER-Q (v1.5.120): Map per-question video and audio data (not shuffled).
                            questionVideoUrl: q.questionVideoUrl || '',
                            questionVideoEnabled: q.questionVideoEnabled ? true : false,
                            questionAudioUrl: q.questionAudioUrl || '',
                            questionAudioEnabled: q.questionAudioEnabled ? true : false,
                            // ADD-SURVEY-FREETEXT (v1.5.127): Preserve question type.
                            questionType: q.questionType || 'scale'
                        };
                    });
                    
                    // Start the quiz
                    startStudentQuiz();
                } else {
                    console.error('[KC] No questions found:', response.error);
                    alert('No questions available. Please contact your teacher.');
                    location.reload();
                }
            },
            error: function (xhr, status, error) {
                console.error('[KC] Load questions failed:', status, error);
                alert('Failed to load questions. Please try again.');
                location.reload();
            }
        });
    }
    
    function startStudentQuiz() {
        // Restore question position for Continue Attempt, or start from Q1 for fresh attempts.
        var totalQs = quizData ? quizData.length : 0;
        if (resumeFromIndex >= totalQs && totalQs > 0) {
            // Student answered every question but did not click Finish (e.g. closed browser
            // after the last answer). Reconstruct score + log from resumeAnswers, then show
            // results directly so the student sees the correct percentage and can download.
            // BUG-KC-RESUME-ALLCOMPLETE fix: old code hard-coded score=0 and left
            // quizAnswerLog=[]  -  results showed 0% and download buttons failed.
            currentAttemptNum = 1;
            quizAnswerLog = [];
            var computedScore = 0;
            if (resumeAnswers) {
                (quizData || []).forEach(function (q, idx) {
                    var savedAns = q.id ? resumeAnswers[String(q.id)] : null;
                    var isCorrect = savedAns ? !!savedAns.iscorrect : null;
                    if (isCorrect) { computedScore++; }
                    var savedOrigIdx = (savedAns && savedAns.answer !== undefined && savedAns.answer !== null)
                        ? parseInt(savedAns.answer, 10) : -1;
                    var shuffledSelectedIdx = (q.shuffledToOriginal && savedOrigIdx >= 0)
                        ? q.shuffledToOriginal.indexOf(savedOrigIdx) : -1;
                    var expIdx = isCorrect ? q.correctAnswer
                        : (shuffledSelectedIdx >= 0 ? shuffledSelectedIdx : q.correctAnswer);
                    quizAnswerLog.push({
                        questionNum:   idx + 1,
                        question:      q.question,
                        options:       q.options ? q.options.slice() : [],
                        correctIndex:  q.correctAnswer,
                        selectedIndex: shuffledSelectedIdx,
                        isCorrect:     isCorrect,
                        attemptNum:    currentAttemptNum,
                        explanation:   q.explanations ? (q.explanations[expIdx] || q.explanations[q.correctAnswer] || '') : ''
                    });
                });
            }
            score = computedScore;
            resumeAnswers = null;
            resumeFromIndex = 0;
            selectedAnswer = null;
            $('#kc-start-section').hide();
            // FIX-KC-LOADING-RETAKE (v1.5.66): reset button text (same as below).
            $('#start-attempt-btn').prop('disabled', false).text(config.retakeQuizStr || 'Retake Quiz');
            // v1.5.52 FIX-VIDEO-GATE: hide video/audio/eta sections when quiz player takes over.
            $('.kc-eta-banner').hide();
            // v1.5.56: show or hide video section during quiz based on teacher setting.
            if (config.showVideoDuringQuiz) {
                $('#kc-video-status').hide(); // hide the gate-progress message; video stays visible
            } else {
                $('#kc-video-section').hide();
            }
            $('#kc-audio-section').hide();
            $('#kc-quiz-player').show();
            showResults();
            return;
        }
        var isResuming = (resumeFromIndex > 0 && resumeFromIndex < totalQs);
        currentQuestionIndex = isResuming ? resumeFromIndex : 0;
        resumeFromIndex = 0; // reset so fresh retakes always start from Q1.

        // BUG-SCORE-RESUME fix: reconstruct previously-earned score from the
        // server's answers dict instead of always resetting to 0.
        //
        // BUG-SCORE-RESUME-V2 (v1.5.0): The server's saveanswer handler stores
        // each answer as {answer: N, iscorrect: bool}.  The old code cast savedOrig
        // (an object) with Number() which always yields NaN, so the comparison
        // `Number(savedOrig) === Number(correctOrig)` was always false  ->  score 0.
        // Fix: read savedAns.iscorrect directly from the server's answer object.
        //
        // BUG-DOWNLOAD-RESUME fix (v1.5.11): quizAnswerLog was never pre-populated
        // for previously-answered questions on Continue Attempt. Only questions answered
        // in the current session were pushed to the log (via checkAnswer()), so the
        // Download PDF / Download Text export omitted all questions before the resume
        // point  -  showing e.g. only Q9-Q10 when the student resumed at Q9.
        // Fix: iterate quizData[0..resumeFromIndex-1] and reconstruct a log entry for
        // each pre-answered question using resumeAnswers.  savedAns.answer is the
        // original (unshuffled) index; convert it to the shuffled display index via
        // shuffledToOriginal.indexOf() so the correct option letter appears in export.
        if (isResuming && resumeAnswers) {
            quizAnswerLog = [];
            currentAttemptNum = 1;
            var computedScore = 0;
            (quizData || []).slice(0, currentQuestionIndex).forEach(function (q, idx) {
                if (!q.id) {
                    // v1.5.13 FIX-DOWNLOAD-MISSING: questions without an ID were silently
                    // dropped from the log. Now include them as placeholders so the download
                    // export contains ALL questions, not just those with saved DB answers.
                    quizAnswerLog.push({
                        questionNum:  idx + 1,
                        question:     q.question,
                        options:      q.options ? q.options.slice() : [],
                        correctIndex: q.correctAnswer,
                        selectedIndex: -1,   // not recoverable  -  no ID
                        isCorrect:    null,
                        attemptNum:   currentAttemptNum,
                        explanation:  q.explanations ? (q.explanations[q.correctAnswer] || '') : ''
                    });
                    return;
                }
                var savedAns = resumeAnswers[String(q.id)];
                // savedAns = {answer: N, iscorrect: bool} from the server's saveanswer handler.
                if (!savedAns) {
                    // v1.5.13 FIX-DOWNLOAD-MISSING: answer not in DB (network failure at save
                    // time). Previously skipped with `return`  ->  question absent from download.
                    // Now include as placeholder with selectedIndex: -1 so all Qs appear in export.
                    quizAnswerLog.push({
                        questionNum:  idx + 1,
                        question:     q.question,
                        options:      q.options ? q.options.slice() : [],
                        correctIndex: q.correctAnswer,
                        selectedIndex: -1,   // not recoverable  -  save failed
                        isCorrect:    null,
                        attemptNum:   currentAttemptNum,
                        explanation:  q.explanations ? (q.explanations[q.correctAnswer] || '') : ''
                    });
                    return;
                }
                if (savedAns.iscorrect) {
                    computedScore++;
                }
                // Convert the stored original index back to its shuffled display position.
                var savedOrigIdx = (savedAns.answer !== undefined && savedAns.answer !== null)
                    ? parseInt(savedAns.answer, 10) : -1;
                var shuffledSelectedIdx = (q.shuffledToOriginal && savedOrigIdx >= 0)
                    ? q.shuffledToOriginal.indexOf(savedOrigIdx) : -1;
                // v1.5.13 FIX-EXPLANATION-FIELD: use the selected answer's explanation when
                // incorrect (each option has its own explanation; wrong-option explanations
                // include "Incorrect... Remember:..." phrasing that the student needs to see).
                var expIdx = savedAns.iscorrect ? q.correctAnswer
                    : (shuffledSelectedIdx >= 0 ? shuffledSelectedIdx : q.correctAnswer);
                quizAnswerLog.push({
                    questionNum:  idx + 1,
                    question:     q.question,
                    options:      q.options ? q.options.slice() : [],
                    correctIndex:  q.correctAnswer,
                    selectedIndex: shuffledSelectedIdx,
                    isCorrect:    !!savedAns.iscorrect,
                    attemptNum:   currentAttemptNum,
                    explanation:  q.explanations ? (q.explanations[expIdx] || q.explanations[q.correctAnswer] || '') : ''
                });
            });
            score = computedScore;
            console.log('[KC] Resume score reconstructed:', score, '/', (quizData || []).length,
                ' -  quizAnswerLog pre-populated with', quizAnswerLog.length, 'prior answers');
        } else if (isResuming) {
            // v1.5.13 FIX-DOWNLOAD-MISSING: resuming but resumeAnswers is null/empty
            // (server returned no saved answers). Previously fell into else  ->  quizAnswerLog=[]
            // so only questions answered in this session appeared in the download.
            // Fix: create question-only placeholders for all pre-resume questions.
            quizAnswerLog = [];
            currentAttemptNum = 1;
            (quizData || []).slice(0, currentQuestionIndex).forEach(function (q, idx) {
                quizAnswerLog.push({
                    questionNum:  idx + 1,
                    question:     q.question,
                    options:      q.options ? q.options.slice() : [],
                    correctIndex: q.correctAnswer,
                    selectedIndex: -1,
                    isCorrect:    null,
                    attemptNum:   currentAttemptNum,
                    explanation:  q.explanations ? (q.explanations[q.correctAnswer] || '') : ''
                });
            });
            score = 0; // can't reconstruct score without saved answers
            console.log('[KC] Resume without saved answers  -  placeholders for', quizAnswerLog.length, 'prior questions');
        } else {
            quizAnswerLog = [];
            currentAttemptNum = 1;
            score = 0;
        }
        resumeAnswers = null; // consumed  -  clear so retakes don't inherit.

        selectedAnswer = null;
        
        $('#kc-start-section').hide();
        // FIX-KC-LOADING-RETAKE (v1.5.66): The start button text was set to 'Loading...'
        // by handleStartAttempt() earlier.  The start section is now hidden, but the
        // button persists in the DOM.  For activities with a video/audio gate, gate.reset()
        // will re-show this section on the next retake  -  if the text is still 'Loading...'
        // the button will look frozen once the gate unlocks.  Reset it to 'Retake Quiz'
        // here (inside the hidden section) so it reads correctly when it re-appears.
        $('#start-attempt-btn').prop('disabled', false).text(config.retakeQuizStr || 'Retake Quiz');
        // v1.5.52 FIX-VIDEO-GATE: hide video/audio/eta sections when quiz player takes over.
        $('.kc-eta-banner').hide();
        // v1.5.56: show or hide video section during quiz based on teacher setting.
        if (config.showVideoDuringQuiz) {
            $('#kc-video-status').hide(); // hide the gate-progress message; video stays visible
        } else {
            $('#kc-video-section').hide();
        }
        $('#kc-audio-section').hide();
        $('#kc-quiz-player').show();
        
        showQuestion();
    }
    
    function saveAnswerToDatabase(questionId, answerIndex, freetextValue, onResult) {
        if (!currentAttemptId) {
            console.log('[KC] No attempt ID, skipping answer save');
            if (typeof onResult === 'function') { onResult(null); }
            return;
        }

        // FIX-RACE-FINISH: track in-flight saves so finishAttempt waits for them all.
        pendingSaves++;

        // ADD-SURVEY-FREETEXT (v1.5.127): Include freetext value when answerIndex === -1.
        var saveData = {
            action: 'saveanswer',
            sesskey: config.sesskey,
            attemptid: currentAttemptId,
            questionid: questionId,
            answerindex: answerIndex
        };
        if (answerIndex === -1 && typeof freetextValue === 'string') {
            saveData.freetextvalue = freetextValue;
        }

        console.log('[KC] Saving answer:', { attemptId: currentAttemptId, questionId: questionId, answerIndex: answerIndex, freetext: answerIndex === -1 });

        $.ajax({
            url: config.wwwroot + '/mod/aiknowledgecheck/ajax.php',
            method: 'POST',
            dataType: 'json',
            data: saveData,
            success: function (response) {
                if (response.ok) {
                    console.log('[KC] Answer saved successfully');
                    if (failedSaves[questionId]) { delete failedSaves[questionId]; }
                } else {
                    console.error('[KC] Failed to save answer:', response.error);
                    failedSaves[questionId] = { answerIndex: answerIndex, freetextValue: freetextValue };
                }
                if (typeof onResult === 'function') { onResult(response); }
            },
            error: function (xhr, status, error) {
                console.error('[KC] Save answer request failed:', status, error);
                // M4: record the failed save so finishAttempt can retry it.
                failedSaves[questionId] = { answerIndex: answerIndex, freetextValue: freetextValue };
                if (typeof onResult === 'function') { onResult(null); }
            },
            complete: function () {
                pendingSaves--;
                if (pendingSaves === 0 && pendingFinishAttempt) {
                    pendingFinishAttempt = false;
                    console.log('[KC] All saves complete  -  executing deferred finishAttempt');
                    doFinishAttempt();
                }
            }
        });
    }

    /**
     * SECURITY (C2): students are not sent the correct answer up-front. When a student checks an
     * answer, persist it and read the authoritative correct index + explanations back from the
     * server, then patch this question object (mapping the server's original-order values back
     * into the client's shuffled order) so the normal reveal logic can run unchanged.
     *
     * @param {Object} q the question object (shuffled) being answered.
     * @param {number} originalIndex the selected answer mapped back to original option order.
     * @param {Function} cb invoked once q has been patched (or left as-is on failure).
     */
    function resolveCorrectAnswer(q, originalIndex, cb) {
        saveAnswerToDatabase(q.id, originalIndex, undefined, function (resp) {
            q._answerSaved = true; // don't double-save on the re-run
            if (resp && typeof resp.correctanswer === 'number') {
                if (q.shuffledToOriginal && q.shuffledToOriginal.length) {
                    var origToShuf = {};
                    for (var i = 0; i < q.shuffledToOriginal.length; i++) {
                        origToShuf[q.shuffledToOriginal[i]] = i;
                    }
                    q.correctAnswer = (origToShuf[resp.correctanswer] !== undefined)
                        ? origToShuf[resp.correctanswer] : resp.correctanswer;
                    if (Array.isArray(resp.explanations)) {
                        var shufExp = [];
                        for (var j = 0; j < q.shuffledToOriginal.length; j++) {
                            shufExp.push(resp.explanations[q.shuffledToOriginal[j]] || '');
                        }
                        q.explanations = shufExp;
                    }
                } else {
                    q.correctAnswer = resp.correctanswer;
                    if (Array.isArray(resp.explanations)) { q.explanations = resp.explanations; }
                }
            } else {
                // Graceful fallback: server gave nothing usable. Keep the quiz functional —
                // treat as "no highlight" rather than throwing. Scoring stays server-side.
                if (q.correctAnswer === null || q.correctAnswer === undefined) { q.correctAnswer = -1; }
                if (!q.explanations) { q.explanations = []; }
            }
            if (typeof cb === 'function') { cb(); }
        });
    }

    /**
     * M4: best-effort resend of any answers whose save previously failed, so the server has
     * the full answer set before it grades. Each resend increments pendingSaves, so the
     * defer-until-saved logic in finishAttempt naturally waits for them.
     */
    function retryFailedSaves() {
        var ids = Object.keys(failedSaves);
        if (!ids.length) { return; }
        console.log('[KC] Retrying', ids.length, 'failed answer save(s) before finishing');
        ids.forEach(function (qid) {
            var info = failedSaves[qid];
            saveAnswerToDatabase(parseInt(qid, 10), info.answerIndex, info.freetextValue);
        });
    }

    function finishAttempt() {
        // M4: resend any previously-failed answer saves first (best effort, one pass).
        retryFailedSaves();
        // FIX-RACE-FINISH: if any saveanswer calls are still in-flight, defer the finish
        // until they all complete so the server sees the full answers JSON.
        if (pendingSaves > 0) {
            console.log('[KC] Deferring finishAttempt  -  ' + pendingSaves + ' save(s) in-flight');
            pendingFinishAttempt = true;
            return;
        }
        doFinishAttempt();
    }

    function doFinishAttempt() {
        // M-4: don't finish SILENTLY when answers are still unsaved. finishAttempt() runs one
        // retry pass first (retryFailedSaves); if any save STILL failed, tell the student so a
        // lost answer isn't a silent surprise on their score. Grading counts an unsaved answer
        // as unanswered (i.e. wrong), so this only ever under-scores — but the learner deserves
        // to know and can reconnect + Retake.
        var unsavedCount = Object.keys(failedSaves).length;
        if (unsavedCount > 0) {
            console.error('[KC] Finishing with ' + unsavedCount + ' unsaved answer(s)');
            try {
                require(['core/notification'], function (Notification) {
                    Notification.alert(
                        'Some answers were not saved',
                        unsavedCount + ' of your answers could not be saved (you may have lost connection). ' +
                        'They will not be counted. If possible, reconnect and use "Retake" to answer them again.'
                    );
                });
            } catch (e) {
                console.warn('[KC] core/notification unavailable for the unsaved-answers warning');
            }
        }

        if (!currentAttemptId) {
            console.log('[KC] No attempt ID, skipping finish');
            // Retake buttons were left disabled  -  enable them now so the student can act.
            $('#retake-quiz-btn').prop('disabled', false);
            $('#retry-wrong-btn').prop('disabled', false);
            return;
        }

        // Capture the attempt ID now. If the student clicks Retake quickly, handleStartAttempt
        // may overwrite currentAttemptId before our AJAX callback fires. We capture it here so
        // we only null out currentAttemptId if it hasn't changed (race condition guard).
        var attemptBeingFinished = currentAttemptId;
        var progressKey = 'kc_progress_' + config.cmid + '_' + attemptBeingFinished;

        console.log('[KC] Finishing attempt:', attemptBeingFinished);
        
        $.ajax({
            url: config.wwwroot + '/mod/aiknowledgecheck/ajax.php',
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'finishattempt',
                sesskey: config.sesskey,
                attemptid: attemptBeingFinished
            },
            success: function (response) {
                if (response.ok) {
                    console.log('[KC] Attempt finished successfully:', response);
                    // Clear saved progress for this attempt from localStorage.
                    localStorage.removeItem(progressKey);
                    // Use server-authoritative counts so the client never drifts out of sync.
                    if (typeof response.attemptsUsed !== 'undefined') {
                        config.attemptsUsed = response.attemptsUsed;
                    } else {
                        config.attemptsUsed = (config.attemptsUsed || 0) + 1;
                    }
                    if (typeof response.canAttempt !== 'undefined') {
                        config.canAttempt = response.canAttempt;
                    }
                    updateAttemptsBadge();
                    // Only clear currentAttemptId if no new attempt has been started
                    // (guards against the race where handleStartAttempt set a new ID first).
                    if (currentAttemptId === attemptBeingFinished) {
                        currentAttemptId = null;
                    }
                } else {
                    console.error('[KC] Failed to finish attempt:', response.error);
                }
                // Always enable the retake buttons once the finish round-trip is complete.
                $('#retake-quiz-btn').prop('disabled', false);
                $('#retry-wrong-btn').prop('disabled', false);
            },
            error: function (xhr, status, error) {
                console.error('[KC] Finish attempt request failed:', status, error);
                $('#retake-quiz-btn').prop('disabled', false);
                $('#retry-wrong-btn').prop('disabled', false);
            }
        });
    }

    function updateAttemptsBadge() {
        var used = config.attemptsUsed || 0;
        var max = config.maxAttempts || 0;
        var usedStr = config.attemptsUsedStr || 'Attempts Used';
        var unlimitedStr = config.attemptsUnlimitedStr || 'Unlimited';
        var label = usedStr + ': ' + used + (max > 0 ? ' / ' + max : ' (' + unlimitedStr + ')');
        // Use innerHTML with an explicit-sized SVG instead of cloneNode.
        // cloneNode clones whatever SVG is currently in the badge  -  if that SVG
        // has no width/height attributes (e.g. the JS-rendered results-screen badge),
        // the browser renders it at the SVG default of 300 x 150 px, making the icon
        // appear enormously enlarged. An inline HTML string with explicit dimensions
        // guarantees a correctly-sized 14 x 14 px icon every time.
        var svgHtml = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" ' +
            'viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
            'stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;vertical-align:-2px">' +
            '<path d="M1 4v6h6"></path>' +
            '<path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path>' +
            '</svg>';
        document.querySelectorAll('.kc-attempts-badge').forEach(function (el) {
            el.innerHTML = svgHtml + ' ' + label;
        });
    }

    function startQuiz() {
        currentQuestionIndex = 0;
        score = 0;
        selectedAnswer = null;
        quizAnswerLog = [];
        currentAttemptNum = 1;
        
        $('#kc-ready-section').hide();
        // v1.5.60 FIX-START-QUIZ-VIDEO: hide video/audio/eta when teacher preview quiz starts
        // (matches the same logic in handleStartAttempt for student-mode)
        $('.kc-eta-banner').hide();
        if (config.showVideoDuringQuiz) {
            $('#kc-video-status').hide();
        } else {
            $('#kc-video-section').hide();
        }
        $('#kc-audio-section').hide();
        $('#kc-quiz-player').show();
        
        showQuestion();
    }

    function showQuestion() {
        var q = quizData[currentQuestionIndex];
        
        $('#question-counter').text('Question ' + (currentQuestionIndex + 1) + ' of ' + quizData.length);
        // ADD-SURVEY-MODE (v1.5.126): Hide score in survey mode.
        if (config.surveyMode) {
            $('#quiz-score').hide();
        } else {
            $('#quiz-score').text('Score: ' + score + '/' + quizData.length);
        }
        $('#question-text').text(q.question);

        // ADD-KC-MEDIAPER-Q (v1.5.120): Unified per-question media gate (image + video + audio).
        // All media types share the acknowledgedQuestions[index] flag. If any media is present
        // and not yet acknowledged, answer options and the check button are locked until the
        // student clicks "I've reviewed this content — Continue".
        $('#kc-question-media').remove();
        var hasQImage  = !!(q.imageEnabled  && q.imageUrl);
        var hasQVideo  = !!(q.questionVideoEnabled && q.questionVideoUrl);
        var hasQAudio  = !!(q.questionAudioEnabled && q.questionAudioUrl);
        var hasQMedia  = hasQImage || hasQVideo || hasQAudio;
        var qMediaAcked    = acknowledgedQuestions[currentQuestionIndex] === true;
        var needsQMediaGate = hasQMedia && !qMediaAcked;

        if (hasQMedia) {
            var qMediaHtml = '<div id="kc-question-media" style="margin-bottom: 14px;">';
            if (hasQImage) {
                qMediaHtml += '<div style="text-align: center; margin-bottom: 10px;">' +
                    '<img src="' + q.imageUrl.replace(/"/g, '&quot;') + '" alt="Question image" style="max-width: 100%; max-height: 400px; border-radius: 8px; object-fit: contain; display: inline-block;">' +
                    '</div>';
            }
            if (hasQVideo) {
                var qVidId = extractYouTubeId(q.questionVideoUrl);
                if (qVidId) {
                    qMediaHtml += '<div style="text-align: center; margin-bottom: 10px;">' +
                        '<div style="position: relative; padding-bottom: 56.25%; height: 0; overflow: hidden; max-width: 640px; margin: 0 auto; border-radius: 8px;">' +
                        '<iframe src="https://www.youtube.com/embed/' + qVidId + '" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; border-radius: 8px;" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>' +
                        '</div></div>';
                }
            }
            if (hasQAudio) {
                qMediaHtml += '<div style="margin-bottom: 10px; text-align: center;">' +
                    '<audio controls preload="auto" style="width: 100%; max-width: 500px;">' +
                    '<source src="' + q.questionAudioUrl.replace(/"/g, '&quot;') + '">' +
                    '</audio></div>';
            }
            if (needsQMediaGate) {
                qMediaHtml += '<div id="kc-q-media-gate" style="text-align: center; margin-top: 10px;">' +
                    '<button id="kc-q-media-ack-btn" class="kc-btn kc-btn-primary" type="button">' +
                    'I\'ve reviewed this content &#8212; Continue' +
                    '</button></div>';
            } else {
                qMediaHtml += '<div style="text-align: center; margin-top: 6px; font-size: 12px; color: #28a745;">' +
                    '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#28a745" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-1px;margin-right:3px;"><polyline points="20 6 9 17 4 12"></polyline></svg>' +
                    'Content reviewed</div>';
            }
            qMediaHtml += '</div>';
            $('#question-text').before(qMediaHtml);
        }

        // Chapter timestamp link  -  show clickable "Jump to X:XX" if enabled and question has a timestamp.
        $('#kc-chapter-stamp').remove();
        if (config.showChapterStamps && q.timestamp_seconds != null && config.hasVideo) {
            var stampSecs = parseInt(q.timestamp_seconds, 10);
            if (!isNaN(stampSecs) && stampSecs >= 0) {
                var kcStampMins = Math.floor(stampSecs / 60);
                var kcStampRem = stampSecs % 60;
                var kcStampTimeStr = kcStampMins + ':' + (kcStampRem < 10 ? '0' : '') + kcStampRem;
                var stampBtn = $('<button id="kc-chapter-stamp" class="kc-chapter-stamp-btn" type="button" data-testid="button-chapter-stamp">' +
                    '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>' +
                    ' Jump to ' + kcStampTimeStr + '</button>');
                stampBtn.on('click', function () {
                    var kcPlayer = window.kcYtPlayer;
                    if (kcPlayer && kcPlayer.seekTo) {
                        kcPlayer.seekTo(stampSecs, true);
                        if (kcPlayer.playVideo) kcPlayer.playVideo();
                        // Ensure video section is visible.
                        var videoSection = document.getElementById('kc-video-section');
                        if (videoSection && videoSection.style.display === 'none') {
                            videoSection.style.display = 'block';
                        }
                        var videoContainer = document.getElementById('kc-video-section');
                        if (videoContainer) {
                            videoContainer.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        }
                    }
                });
                $('#question-text').after(stampBtn);
            }
        }

        // ADD-SURVEY-FREETEXT (v1.5.127): Freetext questions show a textarea instead of options.
        if (q.questionType === 'freetext') {
            $('#options-container').html(
                '<textarea id="kc-freetext-answer" class="kc-freetext-input" rows="5" ' +
                'placeholder="Type your response here..."></textarea>'
            );
            $('#feedback-container').hide();
            $('#check-answer-btn').hide();
            if (currentQuestionIndex < quizData.length - 1) {
                $('#next-question-btn').text('Next').show().prop('disabled', false);
            } else {
                $('#next-question-btn').text('Submit Survey').show().prop('disabled', false);
            }
            selectedAnswer = -1; // Mark as ready (freetext questions are always submittable).
            return;
        }
        
        var optionsHtml = '';
        var letters = ['A', 'B', 'C', 'D', 'E'];
        q.options.forEach(function (option, index) {
            var optionText = (option || '').replace(/\.\s*$/, '').trim();
            // v1.5.52 FIX-OPTION-CAPITALISE: ensure first letter is always uppercase
            // regardless of how the AI or editor stored the option text.
            if (optionText.length > 0) {
                optionText = optionText.charAt(0).toUpperCase() + optionText.slice(1);
            }
            optionsHtml += '<div class="kc-option" data-index="' + index + '">';
            optionsHtml += '<span class="kc-option-letter">' + letters[index] + '</span>';
            optionsHtml += '<span class="kc-option-text">' + escapeHtml(optionText) + '</span>';
            optionsHtml += '</div>';
        });
        
        $('#options-container').html(optionsHtml);
        $('#feedback-container').hide();
        if (config.surveyMode) {
            // Survey scale questions have no correct answer, so they must never expose
            // the quiz-only "Check Answer" step. Selection enables the direct
            // Next/Submit Survey action, matching free-text survey questions.
            $('#check-answer-btn').hide();
            $('#next-question-btn')
                .text(currentQuestionIndex < quizData.length - 1 ? 'Next' : 'Submit Survey')
                .show()
                .prop('disabled', true);
        } else {
            $('#check-answer-btn').show().prop('disabled', true);
            $('#next-question-btn').hide();
        }
        selectedAnswer = null;

        // ADD-KC-MEDIAPER-Q (v1.5.120): Lock options + check button until all media acknowledged.
        if (needsQMediaGate) {
            $('#options-container').css({'visibility': 'hidden', 'pointer-events': 'none'});
            $('#check-answer-btn').hide();
            $('#next-question-btn').hide();
            $('#kc-q-media-ack-btn').on('click', function () {
                acknowledgedQuestions[currentQuestionIndex] = true;
                $('#kc-q-media-gate').replaceWith(
                    '<div style="text-align: center; margin-top: 6px; font-size: 12px; color: #28a745;">' +
                    '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#28a745" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-1px;margin-right:3px;"><polyline points="20 6 9 17 4 12"></polyline></svg>' +
                    'Content reviewed</div>'
                );
                $('#options-container').css({'visibility': 'visible', 'pointer-events': ''});
                if (config.surveyMode) {
                    $('#next-question-btn')
                        .text(currentQuestionIndex < quizData.length - 1 ? 'Next' : 'Submit Survey')
                        .show()
                        .prop('disabled', true);
                } else {
                    $('#check-answer-btn').show().prop('disabled', true);
                }
            });
        }

        // Bind option click
        $('.kc-option').on('click', function () {
            if ($(this).hasClass('disabled')) return;
            
            $('.kc-option').removeClass('selected');
            $(this).addClass('selected');
            selectedAnswer = parseInt($(this).data('index'), 10);
            if (config.surveyMode) {
                $('#next-question-btn').prop('disabled', false);
            } else {
                $('#check-answer-btn').prop('disabled', false);
            }
        });

        // Pre-buffer audio for this question and the next one so voiceover
        // plays with zero delay when the student clicks "Check Answer".
        if (!config.surveyMode) {
            preloadCurrentQuestionAudio(currentQuestionIndex);
            if (currentQuestionIndex + 1 < quizData.length) {
                preloadCurrentQuestionAudio(currentQuestionIndex + 1);
            }
        }
    }

    /**
     * Pre-decode and buffer all audio answers for question at index qi.
     * Stores fully-loaded Audio objects in audioPreloadCache keyed by 'qi_ai'
     * so playExplanationAudio can play them instantly without any decode work.
     */
    function preloadCurrentQuestionAudio(qi) {
        var q = quizData[qi];
        if (!q || !q.audioData || !Array.isArray(q.audioData)) return;
        q.audioData.forEach(function (b64, ai) {
            var cacheKey = qi + '_' + ai;
            if (!b64 || audioPreloadCache[cacheKey]) return; // already cached
            try {
                var raw = atob(b64);
                var arr = new Uint8Array(raw.length);
                for (var i = 0; i < raw.length; i++) arr[i] = raw.charCodeAt(i);
                var blob = new Blob([arr], { type: 'audio/ogg' });
                var url = URL.createObjectURL(blob);
                var aud = new Audio(url);
                aud._blobUrl = url;
                aud.preload = 'auto';
                aud.load(); // start buffering immediately
                audioPreloadCache[cacheKey] = aud;
            } catch (e) {}
        });
    }

    function checkAnswer() {
        if (retryWrongOnly) {
            checkAnswerWrongOnly();
            return;
        }
        // ADD-SURVEY-FREETEXT (v1.5.127): Freetext questions bypass checkAnswer entirely
        // (they set selectedAnswer = -1 and show Next immediately in showQuestion).
        // selectedAnswer === -1 can only reach here if something unexpected fires checkAnswer
        // for a freetext question — skip gracefully.
        if (selectedAnswer === -1) return;
        if (selectedAnswer === null) return;

        // Survey questions advance through nextQuestion(); this handler is quiz-only.
        if (config.surveyMode) {
            return;
        }

        var q = quizData[currentQuestionIndex];

        // SECURITY (C2): for students the correct answer was withheld at load time. Resolve it
        // from the server (authoritative), patch this question, then re-run to reveal as normal.
        if (q.correctAnswer === null || q.correctAnswer === undefined) {
            if (q._resolvingAnswer) { return; }
            q._resolvingAnswer = true;
            $('#check-answer-btn').prop('disabled', true);
            var origIdxResolve = q.shuffledToOriginal ? q.shuffledToOriginal[selectedAnswer] : selectedAnswer;
            resolveCorrectAnswer(q, origIdxResolve, function () {
                q._resolvingAnswer = false;
                checkAnswer();
            });
            return;
        }

        var isCorrect = selectedAnswer === q.correctAnswer;

        // Record per-question result for the results download.
        quizAnswerLog.push({
            questionNum:  currentQuestionIndex + 1,
            question:     q.question,
            options:      q.options ? q.options.slice() : [],
            correctIndex: q.correctAnswer,
            selectedIndex: selectedAnswer,
            isCorrect:    isCorrect,
            attemptNum:   currentAttemptNum,
            // v1.5.13 FIX-EXPLANATION-FIELD: use the selected answer's explanation when
            // incorrect  -  each option in q.explanations[] has its own text; wrong-option
            // explanations include "Incorrect... Remember:..." that the student needs.
            explanation:  q.explanations ? (isCorrect
                ? (q.explanations[q.correctAnswer] || '')
                : (q.explanations[selectedAnswer] || q.explanations[q.correctAnswer] || '')) : ''
        });
        
        // Save answer to database (for student attempts)
        // CRITICAL: Send the ORIGINAL index, not the shuffled one, so the database can correctly compare.
        // (When the answer was resolved from the server just above, q._answerSaved is set so we don't
        // save it a second time here.)
        if (q.id && !q._answerSaved) {
            var originalIndex = q.shuffledToOriginal ? q.shuffledToOriginal[selectedAnswer] : selectedAnswer;
            saveAnswerToDatabase(q.id, originalIndex);
        }
        
        if (isCorrect) {
            score++;
            // Play success sound for correct answer
            playCorrectSound();
        } else {
            // Play incorrect sound for wrong answer
            playIncorrectSound();
        }
        
        // Disable options
        $('.kc-option').addClass('disabled');
        
        // Show correct/incorrect
        $('.kc-option').each(function () {
            var index = parseInt($(this).data('index'), 10);
            if (index === q.correctAnswer) {
                $(this).addClass('correct');
            } else if (index === selectedAnswer && !isCorrect) {
                $(this).addClass('incorrect');
            }
        });
        
        // Show feedback
        // FIX-KC-SELECTED-AUDIO: v1.5.74  -  play the SELECTED option's audio/explanation when wrong,
        // and the correct option's audio/explanation when right.  The previous v1.5.68 approach
        // always played the correct answer's audio, causing students to hear "Correct. ..." while the
        // UI displayed "Incorrect"  -  a confusing and misleading experience.
        // audioData[] and explanations[] are permuted in lockstep by shuffleQuestionAnswers and
        // redistributeCorrectAnswers, so audioData[i] always matches explanations[i] post-shuffle.
        var explanationIdx = isCorrect ? q.correctAnswer : selectedAnswer;
        var explanationToShow = (q.explanations && q.explanations[explanationIdx]) || '';
        $('#feedback-result').text(isCorrect ? 'Correct!' : 'Incorrect').removeClass('correct incorrect').addClass(isCorrect ? 'correct' : 'incorrect');
        $('#feedback-explanation').text(explanationToShow);
        $('#feedback-container').show();
        
        // Hide play button - voiceover auto-plays
        $('#play-audio-btn').hide();
        
        $('#check-answer-btn').hide();
        
        var voiceoverOn = $('#voiceover-toggle').is(':checked') || !!config.voiceoverEnabled;
        var audioIdx = isCorrect ? q.correctAnswer : selectedAnswer; // FIX-KC-SELECTED-AUDIO
        var hasAudioForAnswer = q.audioData && q.audioData[audioIdx];
        var shouldGate = !isCorrect && voiceoverOn && hasAudioForAnswer;

        if (currentQuestionIndex < quizData.length - 1) {
            $('#next-question-btn').text('Next Question').show().prop('disabled', shouldGate);
        } else {
            $('#next-question-btn').text('Finish Quiz').show().prop('disabled', shouldGate);
        }

        if (voiceoverOn && hasAudioForAnswer) {
            playExplanationAudio(q, audioIdx, shouldGate);
        }
        
        if (!config.surveyMode) {
            $('#quiz-score').text('Score: ' + score + '/' + quizData.length);
        }
    }
    
    function playExplanationAudio(question, answerIndex, gateNextButton) {
        console.log('[KC] playExplanationAudio called, answerIndex:', answerIndex, 'gateNextButton:', gateNextButton);
        
        // Double-check audio data exists (caller should have verified, but be safe)
        if (!question.audioData || !question.audioData[answerIndex]) {
            console.log('[KC] No audio data for answer index:', answerIndex);
            if (gateNextButton) {
                $('#next-question-btn').prop('disabled', false);
            }
            return;
        }
        
        var audioBase64 = question.audioData[answerIndex];
        if (!audioBase64) {
            console.log('[KC] Empty audio data for answer index:', answerIndex);
            if (gateNextButton) {
                $('#next-question-btn').prop('disabled', false);
            }
            return;
        }
        
        // Stop any existing audio
        if (audioElement) {
            audioElement.pause();
            audioElement = null;
        }

        // --- Fast path: use pre-buffered Audio object if available ---
        var qi = quizData ? quizData.indexOf(question) : -1;
        var cacheKey = qi + '_' + answerIndex;
        var cachedAud = (qi >= 0 && audioPreloadCache[cacheKey]) ? audioPreloadCache[cacheKey] : null;

        if (cachedAud) {
            cachedAud.currentTime = 0;
            cachedAud.onended = null;
            cachedAud.onerror = null;
            audioElement = cachedAud;

            audioElement.onended = function () {
                if (gateNextButton) {
                    $('#next-question-btn').prop('disabled', false);
                }
            };
            audioElement.onerror = function () {
                if (gateNextButton) {
                    $('#next-question-btn').prop('disabled', false);
                }
            };
            if (gateNextButton) {
                setTimeout(function () {
                    if ($('#next-question-btn').prop('disabled')) {
                        $('#next-question-btn').prop('disabled', false);
                    }
                }, 90000);
            }
            audioElement.play().catch(function () {
                if (gateNextButton) {
                    $('#next-question-btn').prop('disabled', false);
                }
            });
            return;
        }

        // --- Fallback: decode on demand (cache miss) ---
        try {
            var byteCharacters = atob(audioBase64);
            var byteNumbers = new Array(byteCharacters.length);
            for (var i = 0; i < byteCharacters.length; i++) {
                byteNumbers[i] = byteCharacters.charCodeAt(i);
            }
            var byteArray = new Uint8Array(byteNumbers);
            var blob = new Blob([byteArray], { type: 'audio/ogg' });
            var audioUrl = URL.createObjectURL(blob);
            
            audioElement = new Audio(audioUrl);
            
            audioElement.onended = function () {
                URL.revokeObjectURL(audioUrl);
                if (gateNextButton) {
                    $('#next-question-btn').prop('disabled', false);
                }
            };
            
            audioElement.onerror = function () {
                URL.revokeObjectURL(audioUrl);
                if (gateNextButton) {
                    $('#next-question-btn').prop('disabled', false);
                }
            };
            
            // Safety timeout: 90 seconds max wait if audio events never fire
            if (gateNextButton) {
                setTimeout(function () {
                    if ($('#next-question-btn').prop('disabled')) {
                        $('#next-question-btn').prop('disabled', false);
                    }
                }, 90000);
            }
            
            audioElement.play().catch(function () {
                URL.revokeObjectURL(audioUrl);
                if (gateNextButton) {
                    $('#next-question-btn').prop('disabled', false);
                }
            });
        } catch (err) {
            if (gateNextButton) {
                $('#next-question-btn').prop('disabled', false);
            }
        }
    }

    function nextQuestion() {
        if (retryWrongOnly) {
            nextQuestionWrongOnly();
            return;
        }
        stopAudio();

        // Survey questions bypass checkAnswer entirely. Save scale and free-text
        // responses here when the student clicks Next/Submit Survey, without any
        // correct/incorrect grading or feedback phase.
        var cq = quizData[currentQuestionIndex];
        if (config.surveyMode && cq) {
            if (cq.questionType === 'freetext') {
                var ftVal = $('#kc-freetext-answer').val() || '';
                if (cq.id) {
                    saveAnswerToDatabase(cq.id, -1, ftVal);
                }
                quizAnswerLog.push({
                    questionNum:   currentQuestionIndex + 1,
                    question:      cq.question,
                    options:       [],
                    correctIndex:  null,
                    selectedIndex: -1,
                    freetextValue: ftVal,
                    isCorrect:     null,
                    attemptNum:    currentAttemptNum,
                    explanation:   ''
                });
            } else {
                if (selectedAnswer === null) {
                    return;
                }
                $('#next-question-btn').prop('disabled', true);
                $('.kc-option').addClass('disabled');
                if (cq.id) {
                    var originalSurveyIndex = cq.shuffledToOriginal
                        ? cq.shuffledToOriginal[selectedAnswer]
                        : selectedAnswer;
                    saveAnswerToDatabase(cq.id, originalSurveyIndex);
                }
                quizAnswerLog.push({
                    questionNum:   currentQuestionIndex + 1,
                    question:      cq.question,
                    options:       cq.options ? cq.options.slice() : [],
                    correctIndex:  null,
                    selectedIndex: selectedAnswer,
                    isCorrect:     null,
                    attemptNum:    currentAttemptNum,
                    explanation:   ''
                });
            }
        }
        
        if (currentQuestionIndex < quizData.length - 1) {
            currentQuestionIndex++;
            // Save progress so Continue Attempt can resume from this question.
            if (currentAttemptId) {
                var storageKey = 'kc_progress_' + config.cmid + '_' + currentAttemptId;
                localStorage.setItem(storageKey, currentQuestionIndex);
            }
            showQuestion();
        } else {
            showResults();
        }
    }

    function showResults() {
        stopAudio();
        $('#kc-quiz-player').hide();

        // Finish the attempt in the database
        finishAttempt();

        // ADD-SURVEY-MODE (v1.5.126): Survey mode — show a simple "thank you" screen
        // instead of the score ring.
        if (config.surveyMode) {
            var surveyHtml =
                '<div class="kc-results" style="text-align:center; padding: 40px 20px;">' +
                    '<div class="kc-encouragement excellent" style="margin-bottom:24px;">' +
                        '<div class="kc-encouragement-icon">' +
                            '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">' +
                                '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />' +
                            '</svg>' +
                        '</div>' +
                        '<div class="kc-encouragement-text">' +
                            '<h2 class="kc-result-title">Survey Complete</h2>' +
                            '<p class="kc-result-message">Thank you for completing the survey. Your responses have been recorded.</p>' +
                        '</div>' +
                    '</div>' +
                    '<div class="kc-result-actions">' +
                        '<button id="retake-quiz-btn" class="kc-btn-retake" disabled data-testid="button-retake-survey">' +
                            '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>' +
                            'Retake Survey' +
                        '</button>' +
                    '</div>' +
                '</div>';
            $('#kc-results-container').html(surveyHtml).show();
            setTimeout(function () {
                $('#retake-quiz-btn').prop('disabled', false);
            }, 800);
            return;
        }
        
        var percentage = Math.round((score / quizData.length) * 100);
        var incorrect = quizData.length - score;
        var isPerfectScore = (percentage === 100);
        
        // Calculate grade-based pass/fail
        var gradePass = config.gradePass ? parseFloat(config.gradePass) : 0;
        var maxGrade = config.maxGrade ? parseInt(config.maxGrade, 10) : 100;
        var earnedGrade = Math.round((score / quizData.length) * maxGrade * 100) / 100;
        var hasPassingGrade = gradePass > 0;
        var hasPassed = hasPassingGrade && earnedGrade >= gradePass;
        
        // Play celebration when passing grade achieved or perfect score
        if (isPerfectScore || hasPassed) {
            playLevelCompleteSound();
            createConfetti();
        }
        
        // Determine performance tier
        var tier, title, message, encouragementClass, encouragementIcon;
        if (isPerfectScore) {
            tier = 'perfect';
            title = 'Perfect Score!';
            message = 'Outstanding! You\'ve mastered this topic completely.';
            encouragementClass = 'excellent';
            encouragementIcon = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z" /></svg>';
        } else if (hasPassed) {
            tier = 'excellent';
            title = 'Well Done!';
            message = 'You\'ve met the passing grade. Great work!';
            encouragementClass = 'excellent';
            encouragementIcon = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>';
        } else if (percentage >= 80) {
            tier = 'excellent';
            title = 'Excellent Work!';
            message = 'You\'ve demonstrated strong understanding of this topic.';
            encouragementClass = 'excellent';
            encouragementIcon = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>';
        } else if (percentage >= 60) {
            tier = 'good';
            title = 'Good Progress!';
            message = 'You\'re on the right track. Review the explanations to strengthen your knowledge.';
            encouragementClass = '';
            encouragementIcon = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" /></svg>';
        } else {
            tier = 'needs-work';
            title = 'Keep Practicing!';
            message = 'Review the explanations and try again to improve your score.';
            encouragementClass = '';
            encouragementIcon = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" /></svg>';
        }
        
        // Build passing grade message
        var gradeMessage = '';
        if (hasPassingGrade) {
            var earnedDisplay = earnedGrade % 1 === 0 ? earnedGrade.toFixed(0) : earnedGrade.toFixed(1);
            var passDisplay = gradePass % 1 === 0 ? gradePass.toFixed(0) : gradePass.toFixed(1);
            if (hasPassed) {
                gradeMessage = '<div class="kc-grade-result kc-grade-passed">' +
                    '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>' +
                    '<span>Passing grade achieved: ' + earnedDisplay + '/' + maxGrade + ' (required: ' + passDisplay + ')</span>' +
                '</div>';
            } else {
                gradeMessage = '<div class="kc-grade-result kc-grade-failed">' +
                    '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>' +
                    '<span>Passing grade not reached: ' + earnedDisplay + '/' + maxGrade + ' (required: ' + passDisplay + ')</span>' +
                '</div>';
            }
        }
        
        // Calculate ring offset (circumference = 2 * PI * r = 2 * 3.14159 * 65 ~= 408)
        var circumference = 408;
        var offset = circumference - (circumference * percentage / 100);
        
        // -- After Completion logic ------------------------------------------------
        // "Terminal" state = student has answered every question correctly (isPerfectScore)
        // OR the attempt limit is exhausted (!config.canAttempt).
        // The afterCompletion setting only affects the UI in this terminal state.
        //   'lock'     ->  show a padlock notice; no further attempts.
        //   'restart'  ->  show a "Start Again" button that restarts from scratch.
        // In a non-terminal state (attempts remain AND not perfect), both settings
        // show the normal Retry Wrong / Retake Full Quiz controls  -  the student still
        // needs to work on improving their score.
        // Teachers always see the normal retake controls regardless.
        var isTerminal = isPerfectScore || !config.canAttempt;
        var afterCompletion = config.afterCompletion || 'restart';
        var lockedNotice = (config.strings && config.strings.activityLockedNotice)
            ? config.strings.activityLockedNotice
            : 'This activity is now locked. No further attempts are permitted.';
        var startAgainLabel = (config.strings && config.strings.startAgain)
            ? config.strings.startAgain
            : 'Start Again';

        var actionButtonsHtml;
        if (config.isTeacher) {
            // Teachers always see the full retake controls.
            actionButtonsHtml =
                (incorrect > 0 ?
                    '<button id="retry-wrong-btn" class="kc-btn-retake" disabled data-testid="button-retry-wrong">' +
                        '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>' +
                        'Retry Wrong Answers (' + incorrect + ')' +
                    '</button>'
                : '') +
                '<button id="retake-quiz-btn" class="kc-btn-retake kc-btn-retake-secondary" disabled data-testid="button-retake-quiz">' +
                    '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>' +
                    'Retake Full Quiz' +
                '</button>';
        } else if (isTerminal && afterCompletion === 'lock') {
            // Activity locked: student reached 100% or exhausted attempts.
            actionButtonsHtml =
                '<p class="kc-activity-locked">' +
                    '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="width:1.1em;height:1.1em;vertical-align:-0.2em;margin-right:0.35em;">' +
                        '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />' +
                    '</svg>' +
                    lockedNotice +
                '</p>';
        } else if (isTerminal && afterCompletion === 'restart') {
            // Student reached 100% or exhausted attempts  ->  offer a clean restart.
            actionButtonsHtml =
                '<button id="retake-quiz-btn" class="kc-btn-retake" disabled data-testid="button-start-again">' +
                    '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>' +
                    startAgainLabel +
                '</button>';
        } else if (config.canAttempt) {
            // Non-terminal: student still has attempts and didn't score 100%.
            // Show the normal retry/retake controls.
            actionButtonsHtml =
                (incorrect > 0 ?
                    '<button id="retry-wrong-btn" class="kc-btn-retake" disabled data-testid="button-retry-wrong">' +
                        '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>' +
                        'Retry Wrong Answers (' + incorrect + ')' +
                    '</button>'
                : '') +
                '<button id="retake-quiz-btn" class="kc-btn-retake kc-btn-retake-secondary" disabled data-testid="button-retake-quiz">' +
                    '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>' +
                    'Retake Full Quiz' +
                '</button>';
        } else {
            // Attempts exhausted and no afterCompletion setting applies.
            actionButtonsHtml = '<p class="kc-attempts-exhausted">You have used all available attempts.</p>';
        }

        // Build the modern results card
        var html = '<div class="kc-results-card">' +
            '<div class="kc-results-header">' +
                '<span class="kc-results-badge">' +
                    '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>' +
                    'Quiz Complete' +
                '</span>' +
                // v1.3.96: Show attempts badge on results screen so the count is visible
                // between retakes (the start-section badge is hidden during retake flow).
                // BUG-KC-PILL fix: added margin-left:8px so the pills do not touch.
                '<span class="kc-attempts-badge kc-attempts-badge-sm" style="margin-left:8px;">' +
                    '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" /></svg>' +
                '</span>' +
            '</div>' +
            '<div class="kc-results-body">' +
                '<div class="kc-score-ring">' +
                    '<svg viewBox="0 0 160 160">' +
                        '<defs>' +
                            '<linearGradient id="scoreGradient" x1="0%" y1="0%" x2="100%" y2="100%">' +
                                '<stop offset="0%" style="stop-color:#667eea" />' +
                                '<stop offset="100%" style="stop-color:#764ba2" />' +
                            '</linearGradient>' +
                            '<linearGradient id="perfectGradient" x1="0%" y1="0%" x2="100%" y2="100%">' +
                                '<stop offset="0%" style="stop-color:#f59e0b" />' +
                                '<stop offset="50%" style="stop-color:#ef4444" />' +
                                '<stop offset="100%" style="stop-color:#8b5cf6" />' +
                            '</linearGradient>' +
                        '</defs>' +
                        '<circle class="kc-score-ring-bg" cx="80" cy="80" r="65" />' +
                        '<circle class="kc-score-ring-fill ' + tier + '" cx="80" cy="80" r="65" data-target-offset="' + offset + '" />' +
                    '</svg>' +
                    '<div class="kc-score-center">' +
                        '<div class="kc-score-percent ' + tier + '" data-target-percent="' + percentage + '">0%</div>' +
                    '</div>' +
                '</div>' +
                '<h3 class="kc-results-title">' + title + '</h3>' +
                '<p class="kc-results-message">' + message + '</p>' +
                '<div class="kc-results-stats">' +
                    '<div class="kc-results-stat">' +
                        '<div class="kc-results-stat-value correct">' + score + '</div>' +
                        '<div class="kc-results-stat-label">Correct</div>' +
                    '</div>' +
                    '<div class="kc-results-stat">' +
                        '<div class="kc-results-stat-value incorrect">' + incorrect + '</div>' +
                        '<div class="kc-results-stat-label">Incorrect</div>' +
                    '</div>' +
                    '<div class="kc-results-stat">' +
                        '<div class="kc-results-stat-value">' + quizData.length + '</div>' +
                        '<div class="kc-results-stat-label">Questions</div>' +
                    '</div>' +
                '</div>' +
                gradeMessage +
                '<div class="kc-results-actions">' +
                    actionButtonsHtml +
                    '<button id="download-results-pdf-btn" class="kc-btn-download-results" data-testid="button-download-results-pdf">' +
                        '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" /></svg>' +
                        'Download PDF' +
                    '</button>' +
                    '<button id="download-results-text-btn" class="kc-btn-download-results" data-testid="button-download-results-text">' +
                        '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>' +
                        'Download Text' +
                    '</button>' +
                '</div>' +
                (!hasPassed && !isPerfectScore ? 
                    '<div class="kc-encouragement ' + encouragementClass + '">' +
                        encouragementIcon +
                        '<span class="kc-encouragement-text">' + (hasPassingGrade ? 'You need ' + (gradePass % 1 === 0 ? gradePass.toFixed(0) : gradePass.toFixed(1)) + '/' + maxGrade + ' to pass. Review and try again!' : (percentage >= 60 ? 'You\'re close! Focus on the areas you missed.' : 'Practice makes perfect. Review and try again!')) + '</span>' +
                    '</div>' 
                : '') +
            '</div>' +
        '</div>';
        
        // Replace the old results content
        $('#kc-results').html(html).show();
        
        // v1.3.96: Immediately populate the badge added to the results header.
        // finishAttempt() will call this again with the server-authoritative count
        // once its AJAX call resolves, keeping the display in sync.
        updateAttemptsBadge();
        
        // Animate the score ring and percentage counter after render
        setTimeout(function () {
            var ringFill = document.querySelector('.kc-score-ring-fill');
            var percentEl = document.querySelector('.kc-score-percent');
            if (ringFill) {
                var targetOffset = parseFloat(ringFill.getAttribute('data-target-offset'));
                ringFill.style.strokeDashoffset = targetOffset;
            }
            if (percentEl) {
                var targetPercent = parseInt(percentEl.getAttribute('data-target-percent'), 10);
                var duration = 1000;
                var startTime = null;
                function animateCount(timestamp) {
                    if (!startTime) startTime = timestamp;
                    var elapsed = timestamp - startTime;
                    var progress = Math.min(elapsed / duration, 1);
                    var eased = 1 - Math.pow(1 - progress, 3);
                    var current = Math.round(eased * targetPercent);
                    percentEl.textContent = current + '%';
                    if (progress < 1) {
                        requestAnimationFrame(animateCount);
                    }
                }
                requestAnimationFrame(animateCount);
            }
        }, 50);
        
        setTimeout(function () {
            var titleEl = document.querySelector('.kc-results-title');
            var msgEl = document.querySelector('.kc-results-message');
            if (titleEl) {
                titleEl.style.transition = 'opacity 0.8s ease';
                titleEl.style.opacity = '0';
                setTimeout(function () { titleEl.style.display = 'none'; }, 800);
            }
            if (msgEl) {
                msgEl.style.transition = 'opacity 0.8s ease';
                msgEl.style.opacity = '0';
                setTimeout(function () { msgEl.style.display = 'none'; }, 800);
            }
        }, 3000);

        // Rebind action buttons
        $('#retry-wrong-btn').on('click', retakeWrongOnly);
        $('#retake-quiz-btn').on('click', retakeQuiz);
        $('#download-results-pdf-btn').on('click', downloadResultsPDF);
        $('#download-results-text-btn').on('click', downloadResultsText);
    }

    /**
     * Open a print-ready window containing the student's full quiz results.
     * The browser's native print dialog handles PDF conversion (File > Print > Save as PDF).
     */
    function downloadResultsPDF() {
        if (!quizAnswerLog || quizAnswerLog.length === 0) {
            alert('No results to download. Please complete the quiz first.');
            return;
        }

        var labels = ['A', 'B', 'C', 'D'];
        var percentage = Math.round((score / quizData.length) * 100);
        var titleEl = document.querySelector('h2.kc-header-title, h1, title');
        var quizTitle = titleEl ? titleEl.textContent.trim() : 'Knowledge Check';
        var dateStr = new Date().toLocaleDateString(undefined, {year:'numeric', month:'long', day:'numeric'});

        // v1.5.21 ATTEMPT-GROUPED: Build questions HTML grouped by attempt number.
        // Each attempt gets a styled header. Single-attempt quizzes render with no header (backward-compat).
        function buildQuestionCard(a) {
            var optionsHtml = '';
            a.options.forEach(function (opt, i) {
                optionsHtml += '<div style="padding:3px 0;font-size:13px;">' +
                    '<strong>' + labels[i] + '.</strong> ' + escapeHtml(opt) +
                '</div>';
            });
            // v1.5.13 FIX-PLACEHOLDER-DISPLAY: handle entries where answer was not recorded.
            var selectedLetter = (a.selectedIndex >= 0 && a.selectedIndex < labels.length)
                ? labels[a.selectedIndex] : '\u2014';
            var answerLine = a.isCorrect === null
                ? '<span style="color:#6b7280;font-weight:700;">Your answer: ' + selectedLetter + ' (NOT RECORDED)</span>'
                : (a.isCorrect
                    ? '<span style="color:#16a34a;font-weight:700;">Your answer: ' + selectedLetter + ' (CORRECT)</span>'
                    : '<span style="color:#dc2626;font-weight:700;">Your answer: ' + selectedLetter + ' (INCORRECT)</span>');
            var borderColor = a.isCorrect === null ? '#9ca3af' : (a.isCorrect ? '#16a34a' : '#dc2626');
            return '<div style="border:1px solid ' + borderColor + ';border-radius:6px;padding:14px 16px;margin-bottom:16px;page-break-inside:avoid;">' +
                '<p style="margin:0 0 10px;font-weight:600;font-size:14px;">Q' + a.questionNum + '. ' + escapeHtml(a.question) + '</p>' +
                '<div style="margin-bottom:10px;">' + optionsHtml + '</div>' +
                '<p style="margin:0 0 6px;">' + answerLine + '</p>' +
                (a.explanation ? '<p style="margin:0;font-size:12px;color:#555;padding-top:4px;border-top:1px solid #e5e7eb;"><em>Explanation: ' + escapeHtml(a.explanation) + '</em></p>' : '') +
            '</div>';
        }

        // Group log entries by attempt number (preserves attempt order, sorts Qs within each attempt).
        // v1.5.22: Always show "Attempt N" heading  -  even for single-attempt quizzes. Sub-label removed.
        var attemptGroups = {};
        var attemptNums = [];
        quizAnswerLog.forEach(function (a) {
            var num = a.attemptNum || 1;
            if (!attemptGroups[num]) { attemptGroups[num] = []; attemptNums.push(num); }
            attemptGroups[num].push(a);
        });
        attemptNums.sort(function (x, y) { return x - y; });

        var questionsHtml = '';
        attemptNums.forEach(function (attemptNum) {
            var entries = attemptGroups[attemptNum].slice().sort(function (x, y) { return x.questionNum - y.questionNum; });
            var allIncorrect = entries.length > 0 && entries.every(function (a) { return a.isCorrect === false; });
            questionsHtml += '<div style="margin:' + (attemptNum === 1 ? '0' : '28px') + ' 0 14px;page-break-before:' + (attemptNum === 1 ? 'auto' : 'avoid') + ';">' +
                '<h2 style="margin:0 0 14px;font-size:16px;color:#1d4ed8;border-bottom:2px solid #dbeafe;padding-bottom:6px;">Attempt ' + attemptNum + '</h2>' +
                (allIncorrect ? '<p style="margin:0 0 12px;font-size:12px;color:#dc2626;font-style:italic;">No correct answers in this attempt.</p>' : '') +
            '</div>';
            entries.forEach(function (a) {
                questionsHtml += buildQuestionCard(a);
            });
        });

        var safeTitle = escapeHtml(quizTitle);
        var html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">' +
            '<title>' + safeTitle + '  -  Results</title>' +
            '<style>' +
                'body{font-family:Arial,Helvetica,sans-serif;margin:0;padding:24px;color:#111;font-size:13px;}' +
                'h1{font-size:20px;margin:0 0 4px;}' +
                '.subtitle{color:#555;font-size:13px;margin:0 0 16px;}' +
                '.summary{display:flex;gap:24px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:12px 16px;margin-bottom:24px;}' +
                '.summary-item{text-align:center;}' +
                '.summary-value{font-size:28px;font-weight:700;line-height:1;}' +
                '.summary-label{font-size:11px;color:#6b7280;margin-top:2px;}' +
                '.correct-val{color:#16a34a;} .incorrect-val{color:#dc2626;} .pct-val{color:#1d4ed8;}' +
                '@media print{body{padding:16px;} button{display:none;}}' +
            '</style>' +
            '</head><body>' +
            '<h1>' + safeTitle + '  -  Results</h1>' +
            '<p class="subtitle">Date completed: ' + dateStr + '</p>' +
            '<div class="summary">' +
                '<div class="summary-item"><div class="summary-value pct-val">' + percentage + '%</div><div class="summary-label">Score</div></div>' +
                '<div class="summary-item"><div class="summary-value correct-val">' + score + '</div><div class="summary-label">Correct</div></div>' +
                '<div class="summary-item"><div class="summary-value incorrect-val">' + (quizData.length - score) + '</div><div class="summary-label">Incorrect</div></div>' +
                '<div class="summary-item"><div class="summary-value">' + quizData.length + '</div><div class="summary-label">Questions</div></div>' +
            '</div>' +
            questionsHtml +
            '</body></html>';

        var win = window.open('', '_blank');
        if (!win) {
            alert('Please allow pop-ups for this site, then try again.');
            return;
        }
        win.document.write(html);
        win.document.close();
        win.focus();
        setTimeout(function () { win.print(); }, 400);
    }

    /**
     * Download a plain-text file containing the student's quiz results.
     */
    function downloadResultsText() {
        if (!quizAnswerLog || quizAnswerLog.length === 0) {
            alert('No results to download. Please complete the quiz first.');
            return;
        }

        var labels = ['A', 'B', 'C', 'D'];
        var percentage = Math.round((score / quizData.length) * 100);
        var dateStr = new Date().toLocaleDateString(undefined, {year:'numeric', month:'long', day:'numeric'});
        var titleEl = document.querySelector('h2.kc-header-title, h1');
        var quizTitle = titleEl ? titleEl.textContent.trim() : 'Knowledge Check';

        var lines = [];
        lines.push(quizTitle + '  -  Results');
        lines.push('Date completed: ' + dateStr);
        lines.push('Score: ' + score + '/' + quizData.length + ' (' + percentage + '%)');
        lines.push('');
        lines.push('================================================================');
        lines.push('');

        // v1.5.22: Always show "ATTEMPT N" heading  -  even for single-attempt quizzes. Sub-label removed.
        var attemptGroupsTxt = {};
        var attemptNumsTxt = [];
        quizAnswerLog.forEach(function (a) {
            var num = a.attemptNum || 1;
            if (!attemptGroupsTxt[num]) { attemptGroupsTxt[num] = []; attemptNumsTxt.push(num); }
            attemptGroupsTxt[num].push(a);
        });
        attemptNumsTxt.sort(function (x, y) { return x - y; });

        attemptNumsTxt.forEach(function (attemptNum) {
            var entries = attemptGroupsTxt[attemptNum].slice().sort(function (x, y) { return x.questionNum - y.questionNum; });
            var allIncorrectTxt = entries.length > 0 && entries.every(function (a) { return a.isCorrect === false; });

            lines.push('ATTEMPT ' + attemptNum);
            lines.push('----------------------------------------------------------------');
            if (allIncorrectTxt) {
                lines.push('No correct answers in this attempt.');
            }
            lines.push('');

            entries.forEach(function (a) {
                lines.push('Q' + a.questionNum + '. ' + a.question);
                a.options.forEach(function (opt, i) {
                    lines.push(labels[i] + '. ' + opt);
                });
                // v1.5.13 FIX-PLACEHOLDER-DISPLAY: handle not-recorded placeholder entries.
                var selectedLetter = (a.selectedIndex >= 0 && a.selectedIndex < labels.length)
                    ? labels[a.selectedIndex] : '\u2014';
                var answerStatus = a.isCorrect === null ? 'NOT RECORDED' : (a.isCorrect ? 'CORRECT' : 'INCORRECT');
                lines.push('Your answer: ' + selectedLetter + ' (' + answerStatus + ')');
                if (a.explanation) {
                    lines.push('Explanation: ' + a.explanation);
                }
                lines.push('');
            });

            lines.push('================================================================');
            lines.push('');
        });

        var content = lines.join('\n');
        var blob = new Blob([content], {type: 'text/plain;charset=utf-8;'});
        var url = window.URL.createObjectURL(blob);
        var link = document.createElement('a');
        link.href = url;
        link.download = 'quiz-results.txt';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        window.URL.revokeObjectURL(url);
    }

    var retryWrongOnly = false;
    var wrongQuestionIndices = [];
    var retryCorrectCarryOver = 0;
    // FIX-KC-IMAGEGATE-HARDGATE (v1.5.119): tracks which question indices the student has
    // acknowledged the per-question image for. Reset on retake so each new attempt re-gates.
    var acknowledgedQuestions = {};

    function retakeQuiz() {
        retryWrongOnly = false;
        wrongQuestionIndices = [];
        retryCorrectCarryOver = 0;
        acknowledgedQuestions = {};
        $('#kc-results').hide();
        if (config.isTeacher) {
            startQuiz();
        } else if (window.kcGate && window.kcGate.hasLocks()) {
            // FIX-KC-VIDEO-GATE: re-show video/audio sections and re-lock the gate so
            // the student must re-watch before starting their next attempt.
            window.kcGate.reset();
            $('#kc-video-section').show();
            $('#kc-audio-section').show();
            // 'Start Quiz' button is re-disabled by kcGate.reset(); student will click it
            // after the gate unlocks, which calls handleStartAttempt() via the bound handler.
        } else {
            handleStartAttempt();
        }
    }

    function retakeWrongOnly() {
        wrongQuestionIndices = [];
        retryCorrectCarryOver = 0;

        // BUG-MISSING-ATTEMPT-HISTORY (v1.5.24): quizAnswerLog now preserves ALL incorrect
        // entries across attempts (so the PDF shows every attempt). A simple forEach would
        // double-count questions that appear multiple times (e.g. Q3x in attempt 1 AND
        // attempt 2). Fix: find the LATEST answer per question, then use only that to decide
        // correct/wrong status.
        var latestByQNum = {};
        quizAnswerLog.forEach(function (entry) {
            var qn = entry.questionNum;
            if (!latestByQNum[qn] || (entry.attemptNum || 1) >= (latestByQNum[qn].attemptNum || 1)) {
                latestByQNum[qn] = entry;
            }
        });
        var qNums = Object.keys(latestByQNum);
        for (var ki = 0; ki < qNums.length; ki++) {
            var latest = latestByQNum[qNums[ki]];
            if (latest.isCorrect) {
                retryCorrectCarryOver++;
            } else {
                wrongQuestionIndices.push(latest.questionNum - 1);
            }
        }
        if (wrongQuestionIndices.length === 0) {
            retakeQuiz();
            return;
        }
        retryWrongOnly = true;
        $('#kc-results').hide();
        if (config.isTeacher) {
            startQuizWrongOnly();
        } else {
            handleStartAttemptWrongOnly();
        }
    }

    function handleStartAttemptWrongOnly() {
        pendingSaves = 0;
        pendingFinishAttempt = false;
        $('#start-attempt-btn').prop('disabled', true).text('Loading...');
        $.ajax({
            url: config.wwwroot + '/mod/aiknowledgecheck/ajax.php',
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'startattempt',
                sesskey: config.sesskey,
                cmid: config.cmid
            },
            success: function (response) {
                if (response.ok) {
                    currentAttemptId = response.attemptid;
                    preSaveCorrectAnswers(function () {
                        startQuizWrongOnly();
                    });
                } else {
                    alert(response.error || 'Failed to start attempt');
                    retryWrongOnly = false;
                }
            },
            error: function () {
                alert('Failed to start quiz. Please try again.');
                retryWrongOnly = false;
            }
        });
    }

    function preSaveCorrectAnswers(callback) {
        var correctQs = [];
        quizData.forEach(function (q, idx) {
            if (wrongQuestionIndices.indexOf(idx) === -1 && q.id) {
                correctQs.push(q);
            }
        });
        if (correctQs.length === 0) {
            callback();
            return;
        }
        // FIX-RACE-PRESAVE: save carry-forward answers SEQUENTIALLY to avoid the
        // PHP read-modify-write race condition.  The saveanswer handler does:
        //   READ answers JSON  ->  merge one entry  ->  WRITE back.
        // Firing all requests in parallel means every request reads '{}'
        // simultaneously and the last writer wins  -  only 1 of N carry-forward
        // answers actually persists.  Sequential saves guarantee each write is
        // visible to the next reader.
        function saveNext(i) {
            if (i >= correctQs.length) {
                callback();
                return;
            }
            var q = correctQs[i];
            var origIdx = q.correctAnswer;
            if (q.shuffledToOriginal) {
                origIdx = q.shuffledToOriginal[q.correctAnswer];
            }
            $.ajax({
                url: config.wwwroot + '/mod/aiknowledgecheck/ajax.php',
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'saveanswer',
                    sesskey: config.sesskey,
                    attemptid: currentAttemptId,
                    questionid: q.id,
                    answerindex: origIdx
                },
                complete: function () {
                    saveNext(i + 1);
                }
            });
        }
        saveNext(0);
    }

    function startQuizWrongOnly() {
        currentQuestionIndex = 0;
        score = retryCorrectCarryOver;
        selectedAnswer = null;

        // v1.5.21 ATTEMPT-TRACKING: snapshot the current log so carry-forward entries
        // can preserve their original attempt number in the rebuilt log.
        var previousLog = quizAnswerLog.slice();
        currentAttemptNum++;   // new attempt number for wrong-only retry questions
        console.log('[KC] Starting wrong-only retry  -  attempt', currentAttemptNum,
            ' -  wrong Q indices:', wrongQuestionIndices);

        quizAnswerLog = [];

        // Step 1: carry-forward one correct entry per already-correct question,
        // preserving the attemptNum of the LATEST correct answer in the snapshot
        // (iterate in reverse so the first match found is the most recent one).
        quizData.forEach(function (q, idx) {
            if (wrongQuestionIndices.indexOf(idx) === -1) {
                var prevEntry = null;
                for (var pi = previousLog.length - 1; pi >= 0; pi--) {
                    if (previousLog[pi].questionNum === idx + 1 && previousLog[pi].isCorrect) {
                        prevEntry = previousLog[pi];
                        break;
                    }
                }
                quizAnswerLog.push({
                    questionNum:  idx + 1,
                    question:     q.question,
                    options:      q.options ? q.options.slice() : [],
                    correctIndex: q.correctAnswer,
                    selectedIndex: q.correctAnswer,
                    isCorrect:    true,
                    attemptNum:   prevEntry ? (prevEntry.attemptNum || (currentAttemptNum - 1)) : (currentAttemptNum - 1),
                    explanation:  q.explanations ? (q.explanations[q.correctAnswer] || '') : ''
                });
            }
        });

        // Step 2: BUG-MISSING-ATTEMPT-HISTORY (v1.5.24): preserve ALL incorrect entries
        // from previous attempts so the PDF/text download shows every attempt including
        // ones where the student answered wrong. Without this, the entry for attempt N
        // where a question was answered incorrectly was silently discarded on the next
        // rebuild, leaving a gap (e.g. "Attempt 3 is missing") in the exported file.
        previousLog.forEach(function (prevEntry) {
            if (!prevEntry.isCorrect) {
                quizAnswerLog.push(prevEntry);
            }
        });

        $('#kc-ready-section').hide();
        $('#kc-quiz-player').show();

        showQuestionWrongOnly();
    }

    function showQuestionWrongOnly() {
        if (currentQuestionIndex >= wrongQuestionIndices.length) {
            retryWrongOnly = false;
            showResults();
            return;
        }
        var realIdx = wrongQuestionIndices[currentQuestionIndex];
        var q = quizData[realIdx];

        $('#question-counter').text('Question ' + (currentQuestionIndex + 1) + ' of ' + wrongQuestionIndices.length + ' (retry)');
        $('#quiz-score').text('Score: ' + score + '/' + quizData.length);
        $('#question-text').text(q.question);

        // ADD-KC-MEDIAPER-Q (v1.5.120): Unified per-question media gate in wrong-only retry.
        // Keyed by realIdx so media already acknowledged in round 1 stays unlocked in retry round.
        $('#kc-question-media').remove();
        var hasWQImage  = !!(q.imageEnabled  && q.imageUrl);
        var hasWQVideo  = !!(q.questionVideoEnabled && q.questionVideoUrl);
        var hasWQAudio  = !!(q.questionAudioEnabled && q.questionAudioUrl);
        var hasWQMedia  = hasWQImage || hasWQVideo || hasWQAudio;
        var wqMediaAcked    = acknowledgedQuestions[realIdx] === true;
        var needsWQMediaGate = hasWQMedia && !wqMediaAcked;

        if (hasWQMedia) {
            var wqMediaHtml = '<div id="kc-question-media" style="margin-bottom: 14px;">';
            if (hasWQImage) {
                wqMediaHtml += '<div style="text-align: center; margin-bottom: 10px;">' +
                    '<img src="' + q.imageUrl.replace(/"/g, '&quot;') + '" alt="Question image" style="max-width: 100%; max-height: 400px; border-radius: 8px; object-fit: contain; display: inline-block;">' +
                    '</div>';
            }
            if (hasWQVideo) {
                var wqVidId = extractYouTubeId(q.questionVideoUrl);
                if (wqVidId) {
                    wqMediaHtml += '<div style="text-align: center; margin-bottom: 10px;">' +
                        '<div style="position: relative; padding-bottom: 56.25%; height: 0; overflow: hidden; max-width: 640px; margin: 0 auto; border-radius: 8px;">' +
                        '<iframe src="https://www.youtube.com/embed/' + wqVidId + '" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; border-radius: 8px;" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>' +
                        '</div></div>';
                }
            }
            if (hasWQAudio) {
                wqMediaHtml += '<div style="margin-bottom: 10px; text-align: center;">' +
                    '<audio controls style="width: 100%; max-width: 500px;">' +
                    '<source src="' + q.questionAudioUrl.replace(/"/g, '&quot;') + '">' +
                    '</audio></div>';
            }
            if (needsWQMediaGate) {
                wqMediaHtml += '<div id="kc-q-media-gate" style="text-align: center; margin-top: 10px;">' +
                    '<button id="kc-q-media-ack-btn" class="kc-btn kc-btn-primary" type="button">' +
                    'I\'ve reviewed this content &#8212; Continue' +
                    '</button></div>';
            } else {
                wqMediaHtml += '<div style="text-align: center; margin-top: 6px; font-size: 12px; color: #28a745;">' +
                    '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#28a745" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-1px;margin-right:3px;"><polyline points="20 6 9 17 4 12"></polyline></svg>' +
                    'Content reviewed</div>';
            }
            wqMediaHtml += '</div>';
            $('#question-text').before(wqMediaHtml);
        }

        var optionsHtml = '';
        var letters = ['A', 'B', 'C', 'D', 'E'];
        q.options.forEach(function (option, index) {
            var optionText = (option || '').replace(/\.\s*$/, '').trim();
            // v1.5.52 FIX-OPTION-CAPITALISE: ensure first letter is always uppercase.
            if (optionText.length > 0) {
                optionText = optionText.charAt(0).toUpperCase() + optionText.slice(1);
            }
            optionsHtml += '<div class="kc-option" data-index="' + index + '">';
            optionsHtml += '<span class="kc-option-letter">' + letters[index] + '</span>';
            optionsHtml += '<span class="kc-option-text">' + escapeHtml(optionText) + '</span>';
            optionsHtml += '</div>';
        });

        $('#options-container').html(optionsHtml);
        $('#feedback-container').hide();
        $('#check-answer-btn').show().prop('disabled', true);
        $('#next-question-btn').hide();
        selectedAnswer = null;

        // ADD-KC-MEDIAPER-Q (v1.5.120): Lock options + check button until all media acknowledged.
        if (needsWQMediaGate) {
            $('#options-container').css({'visibility': 'hidden', 'pointer-events': 'none'});
            $('#check-answer-btn').hide();
            $('#kc-q-media-ack-btn').on('click', function () {
                acknowledgedQuestions[realIdx] = true;
                $('#kc-q-media-gate').replaceWith(
                    '<div style="text-align: center; margin-top: 6px; font-size: 12px; color: #28a745;">' +
                    '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#28a745" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-1px;margin-right:3px;"><polyline points="20 6 9 17 4 12"></polyline></svg>' +
                    'Content reviewed</div>'
                );
                $('#options-container').css({'visibility': 'visible', 'pointer-events': ''});
                $('#check-answer-btn').show().prop('disabled', true);
            });
        }

        $('.kc-option').on('click', function () {
            if ($(this).hasClass('disabled')) return;
            $('.kc-option').removeClass('selected');
            $(this).addClass('selected');
            selectedAnswer = parseInt($(this).data('index'), 10);
            $('#check-answer-btn').prop('disabled', false);
        });
    }

    function checkAnswerWrongOnly() {
        if (selectedAnswer === null) return;

        var realIdx = wrongQuestionIndices[currentQuestionIndex];
        var q = quizData[realIdx];

        // SECURITY (C2): resolve the withheld correct answer from the server, then re-run (retry mode).
        if (q.correctAnswer === null || q.correctAnswer === undefined) {
            if (q._resolvingAnswer) { return; }
            q._resolvingAnswer = true;
            $('#check-answer-btn').prop('disabled', true);
            var origIdxResolveWO = q.shuffledToOriginal ? q.shuffledToOriginal[selectedAnswer] : selectedAnswer;
            resolveCorrectAnswer(q, origIdxResolveWO, function () {
                q._resolvingAnswer = false;
                checkAnswerWrongOnly();
            });
            return;
        }

        var isCorrect = selectedAnswer === q.correctAnswer;

        quizAnswerLog.push({
            questionNum:  realIdx + 1,
            question:     q.question,
            options:      q.options ? q.options.slice() : [],
            correctIndex: q.correctAnswer,
            selectedIndex: selectedAnswer,
            isCorrect:    isCorrect,
            attemptNum:   currentAttemptNum,
            explanation:  q.explanations ? (isCorrect
                ? (q.explanations[q.correctAnswer] || '')
                : (q.explanations[selectedAnswer] || q.explanations[q.correctAnswer] || '')) : ''
        });

        if (q.id && !q._answerSaved) {
            var originalIndex = q.shuffledToOriginal ? q.shuffledToOriginal[selectedAnswer] : selectedAnswer;
            saveAnswerToDatabase(q.id, originalIndex);
        }

        if (isCorrect) {
            score++;
            playCorrectSound();
        } else {
            playIncorrectSound();
        }

        $('.kc-option').addClass('disabled');
        $('.kc-option').each(function () {
            var index = parseInt($(this).data('index'), 10);
            if (index === q.correctAnswer) {
                $(this).addClass('correct');
            } else if (index === selectedAnswer && !isCorrect) {
                $(this).addClass('incorrect');
            }
        });

        // FIX-KC-SELECTED-AUDIO: v1.5.74  -  play selected option's audio/explanation (retry mode).
        var explanationIdxWO = isCorrect ? q.correctAnswer : selectedAnswer;
        var explanationToShowWO = (q.explanations && q.explanations[explanationIdxWO]) || '';
        $('#feedback-result').text(isCorrect ? 'Correct!' : 'Incorrect').removeClass('correct incorrect').addClass(isCorrect ? 'correct' : 'incorrect');
        $('#feedback-explanation').text(explanationToShowWO);
        $('#feedback-container').show();
        $('#play-audio-btn').hide();
        $('#check-answer-btn').hide();

        var voiceoverOn = $('#voiceover-toggle').is(':checked') || !!config.voiceoverEnabled;
        var audioIdxWO = isCorrect ? q.correctAnswer : selectedAnswer; // FIX-KC-SELECTED-AUDIO
        var hasAudioForAnswer = q.audioData && q.audioData[audioIdxWO];
        var shouldGate = !isCorrect && voiceoverOn && hasAudioForAnswer;

        if (currentQuestionIndex < wrongQuestionIndices.length - 1) {
            $('#next-question-btn').text('Next Question').show().prop('disabled', shouldGate);
        } else {
            $('#next-question-btn').text('Finish Quiz').show().prop('disabled', shouldGate);
        }

        if (voiceoverOn && hasAudioForAnswer) {
            playExplanationAudio(q, audioIdxWO, shouldGate);
        }

        $('#quiz-score').text('Score: ' + score + '/' + quizData.length);
    }

    function nextQuestionWrongOnly() {
        stopAudio();
        if (currentQuestionIndex < wrongQuestionIndices.length - 1) {
            currentQuestionIndex++;
            if (currentAttemptId) {
                var storageKey = 'kc_progress_' + config.cmid + '_' + currentAttemptId;
                localStorage.setItem(storageKey, currentQuestionIndex);
            }
            showQuestionWrongOnly();
        } else {
            retryWrongOnly = false;
            showResults();
        }
    }

    function stopAudio() {
        if (audioElement) {
            audioElement.pause();
            audioElement = null;
        }
    }
    
    // ==========================================
    // EDIT MODE FUNCTIONS
    // ==========================================
    
    var originalQuizData = null; // Store original data for cancel
    
    function showEditMode() {
        // FIX-KC-GUARD-EDITMODE: Abort if quizData is empty to prevent showing blank edit
        // forms which, when saved, would wipe all questions from the database.
        if (!quizData || quizData.length === 0) {
            alert('No questions to edit. Please generate questions first, or reload the page if questions have already been generated.');
            return;
        }

        console.log('[KC] Entering edit mode with', quizData.length, 'questions');
        
        // Check for in-progress attempts and warn teacher
        if (config.inProgressAttempts && config.inProgressAttempts > 0) {
            var msg = 'Warning: There ' + (config.inProgressAttempts === 1 ? 'is 1 student' : 'are ' + config.inProgressAttempts + ' students') + 
                ' with in-progress attempts. Editing questions while students are taking the quiz may cause inconsistencies.\n\nDo you want to continue?';
            if (!confirm(msg)) {
                return;
            }
        }
        
        // Store original data for cancel
        originalQuizData = JSON.parse(JSON.stringify(quizData));

        var readyInstructions = $('#ready-extra-instructions').val() || '';
        $('#edit-extra-instructions').val(readyInstructions);
        updateRegenCountDisplay();
        
        // Hide ready section, show edit section
        $('#kc-ready-section').hide();
        $('#kc-edit-section').show();
        
        // Build edit forms for each question
        buildEditForms();
    }
    
    function buildEditForms() {
        var container = $('#edit-questions-container');
        container.empty();
        
        quizData.forEach(function (q, idx) {
            var correctAnswer = q.correctAnswer !== undefined ? q.correctAnswer : 0;
            // FIX-KC-EDIT-SURVEY (v1.5.139): the editor was written for 4-option quizzes and
            // was never updated for survey mode.
            var isFreeText = (q.questionType === 'freetext');
            var isSurvey   = !!config.surveyMode;
            
            var html = '<div class="kc-edit-question" data-question-index="' + idx + '"' +
                ' data-question-type="' + (isFreeText ? 'freetext' : 'scale') + '">' +
                '<div class="kc-edit-question-header">' +
                    '<span class="kc-edit-question-number">Question ' + (idx + 1) + '</span>' +
                    '<div class="kc-edit-question-actions">' +
                        '<button type="button" class="kc-btn-delete-question" data-index="' + idx + '" title="Delete this question">' +
                            '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>' +
                        '</button>' +
                    '</div>' +
                '</div>' +
                '<div class="kc-edit-field">' +
                    '<label>Question Text</label>' +
                    '<textarea class="kc-edit-question-text" data-index="' + idx + '" rows="3">' + escapeHtml(q.question) + '</textarea>' +
                '</div>';

            if (isFreeText) {
                // Free-text questions have no answer options — the student types a response.
                // Rendering them as multiple choice previously made them unsaveable ("Option A
                // cannot be empty") and reset them to scale questions on save.
                html += '<div class="kc-edit-options kc-edit-options-freetext">' +
                    '<label>Answer Format</label>' +
                    '<div style="padding: 10px 12px; background: #f8f9fa; border: 1px dashed #ced4da; border-radius: 6px; font-size: 13px; color: #6c757d;">' +
                        '<strong>Free text response.</strong> Students type their own answer, so this ' +
                        'question has no answer options and no correct answer.' +
                    '</div>' +
                '</div>';
            } else {
                html += '<div class="kc-edit-options">' +
                    '<label>Answer Options' +
                        (isSurvey ? '' : ' <span class="kc-edit-hint">(select the correct answer)</span>') +
                    '</label>';

                // Render exactly as many options as the question actually has, rather than a
                // hardcoded 4. Survey scales may be 2, 3, 4 or 5 points; forcing a floor of 4
                // would render blank boxes that then fail the non-empty validation on save.
                // Quiz questions keep the historic 4-option minimum.
                var optionLabels = ['A', 'B', 'C', 'D', 'E'];
                var optCount = (q.options && q.options.length) ? q.options.length : 4;
                if (!isSurvey && optCount < 4) { optCount = 4; }
                if (optCount < 1) { optCount = 1; }
                if (optCount > 5) { optCount = 5; }

                for (var i = 0; i < optCount; i++) {
                    var optionText = q.options && q.options[i] ? q.options[i] : '';
                    var isCorrect = (!isSurvey && correctAnswer === i);
                    var explanation = q.explanations && q.explanations[i] ? q.explanations[i] : '';
                
                html += '<div class="kc-edit-option ' + (isCorrect ? 'kc-edit-option-correct' : '') + '">' +
                    '<div class="kc-edit-option-header">' +
                        '<label class="kc-edit-option-radio">' +
                            (isSurvey ? '' :
                             '<input type="radio" name="correct-' + idx + '" value="' + i + '" ' + (isCorrect ? 'checked' : '') + '>') +
                            '<span class="kc-option-label">' + optionLabels[i] + '</span>' +
                        '</label>' +
                        '<input type="text" class="kc-edit-option-text" data-question="' + idx + '" data-option="' + i + '" value="' + escapeAttr(optionText) + '" placeholder="Option ' + optionLabels[i] + '">' +
                    '</div>' +
                    '<div class="kc-edit-explanation"' + (isSurvey ? ' style="display:none;"' : '') + '>' +
                        '<textarea class="kc-edit-explanation-text" data-question="' + idx + '" data-option="' + i + '" rows="2" placeholder="Explanation for this option...">' + escapeHtml(explanation) + '</textarea>' +
                    '</div>' +
                '</div>';
                }
            }
            
            // ADD-KC-IMAGEGATE (v1.5.115): Per-question image controls.
            var imgEnabled = q.imageEnabled ? true : false;
            var imgUrl = q.imageUrl || '';
            html += '<div class="kc-edit-imagegate" style="border-top: 1px solid #eee; margin-top: 10px; padding-top: 10px;">' +
                '<label style="display: flex; align-items: center; gap: 8px; cursor: pointer; margin-bottom: 8px; font-size: 13px;">' +
                    '<input type="checkbox" class="kc-edit-image-enabled" data-index="' + idx + '"' + (imgEnabled ? ' checked' : '') + '>' +
                    '<span>Show image with this question</span>' +
                '</label>' +
                '<div class="kc-edit-image-fields" style="' + (imgEnabled ? '' : 'display:none;') + '">' +
                    '<div style="display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 6px;">' +
                        '<input type="url" class="kc-edit-image-url" data-index="' + idx + '" value="' + escapeAttr(imgUrl) + '" placeholder="https://example.com/image.jpg" style="flex: 1; min-width: 200px; padding: 5px 8px; border: 1px solid #ced4da; border-radius: 4px; font-size: 12px;">' +
                        '<button type="button" class="kc-btn kc-btn-secondary kc-question-imagegen-btn" data-index="' + idx + '" style="font-size: 12px; white-space: nowrap;">Generate (5 credits)</button>' +
                    '</div>' +
                    '<div class="kc-edit-image-preview" style="' + (imgUrl && imgEnabled ? '' : 'display:none;') + '">' +
                        '<img class="kc-edit-image-preview-img" src="' + escapeAttr(imgUrl) + '" alt="Preview" style="max-width: 200px; max-height: 150px; border-radius: 6px; object-fit: contain; display: block; margin-bottom: 4px;">' +
                    '</div>' +
                    '<div class="kc-question-imagegen-status" data-index="' + idx + '" style="font-size: 11px; color: #6c757d; display: none;"></div>' +
                '</div>' +
            '</div>';

            // ADD-KC-MEDIAPER-Q (v1.5.120): Per-question YouTube video controls.
            var vidEnabled = q.questionVideoEnabled ? true : false;
            var vidUrl     = q.questionVideoUrl || '';
            var vidId      = extractYouTubeId(vidUrl);
            var vidThumbHtml = (vidUrl && vidEnabled && vidId)
                ? '<div class="kc-edit-video-preview" style="margin-bottom:6px;"><img src="https://img.youtube.com/vi/' + vidId + '/hqdefault.jpg" alt="Thumbnail" style="max-width:200px;border-radius:6px;display:block;"></div>'
                : '<div class="kc-edit-video-preview" style="display:none;margin-bottom:6px;"><img class="kc-edit-video-thumb-img" alt="Thumbnail" src="" style="max-width:200px;border-radius:6px;display:block;"></div>';
            html += '<div class="kc-edit-videomedia" style="border-top:1px solid #eee;margin-top:8px;padding-top:8px;">' +
                '<label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-bottom:8px;font-size:13px;">' +
                    '<input type="checkbox" class="kc-edit-video-enabled" data-index="' + idx + '"' + (vidEnabled ? ' checked' : '') + '>' +
                    '<span>Show YouTube video with this question</span>' +
                '</label>' +
                '<div class="kc-edit-video-fields" style="' + (vidEnabled ? '' : 'display:none;') + '">' +
                    '<input type="url" class="kc-edit-video-url" data-index="' + idx + '" value="' + escapeAttr(vidUrl) + '" placeholder="https://www.youtube.com/watch?v=..." style="width:100%;box-sizing:border-box;padding:5px 8px;border:1px solid #ced4da;border-radius:4px;font-size:12px;margin-bottom:6px;">' +
                    vidThumbHtml +
                '</div>' +
            '</div>';

            // ADD-KC-MEDIAPER-Q (v1.5.120): Per-question audio controls.
            var audEnabled = q.questionAudioEnabled ? true : false;
            var audUrl     = q.questionAudioUrl || '';
            var audPlayerHtml = (audUrl && audEnabled)
                ? '<div class="kc-edit-audio-player" style="margin-bottom:6px;"><audio controls style="width:100%;max-width:340px;display:block;"><source src="' + escapeAttr(audUrl) + '"></audio></div>'
                : '<div class="kc-edit-audio-player" style="display:none;margin-bottom:6px;"><audio controls style="width:100%;max-width:340px;display:block;"></audio></div>';
            html += '<div class="kc-edit-audiomedia" style="border-top:1px solid #eee;margin-top:8px;padding-top:8px;">' +
                '<label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-bottom:8px;font-size:13px;">' +
                    '<input type="checkbox" class="kc-edit-audio-enabled" data-index="' + idx + '"' + (audEnabled ? ' checked' : '') + '>' +
                    '<span>Play audio clip with this question</span>' +
                '</label>' +
                '<div class="kc-edit-audio-fields" style="' + (audEnabled ? '' : 'display:none;') + '">' +
                    '<input type="url" class="kc-edit-audio-url" data-index="' + idx + '" value="' + escapeAttr(audUrl) + '" placeholder="https://example.com/audio.mp3" style="width:100%;box-sizing:border-box;padding:5px 8px;border:1px solid #ced4da;border-radius:4px;font-size:12px;margin-bottom:6px;">' +
                    audPlayerHtml +
                '</div>' +
            '</div>';

            html += '</div></div>';
            container.append(html);
        });
        
        // Bind events
        // ADD-KC-IMAGEGATE: toggle image fields visibility when checkbox changes.
        container.find('.kc-edit-image-enabled').on('change', function () {
            var $fields = $(this).closest('.kc-edit-imagegate').find('.kc-edit-image-fields');
            if ($(this).is(':checked')) {
                $fields.show();
            } else {
                $fields.hide();
            }
        });

        // ADD-KC-IMAGEGATE: bind per-question image generation buttons.
        container.find('.kc-question-imagegen-btn').on('click', function () {
            var qIdx = parseInt($(this).data('index'));
            var $btn = $(this);
            var $urlInput = $(this).closest('.kc-edit-image-fields').find('.kc-edit-image-url');
            var $statusDiv = $(this).closest('.kc-edit-imagegate').find('.kc-question-imagegen-status[data-index="' + qIdx + '"]');
            var $previewDiv = $(this).closest('.kc-edit-image-fields').find('.kc-edit-image-preview');
            var $previewImg = $previewDiv.find('.kc-edit-image-preview-img');
            var promptText = quizData[qIdx] ? quizData[qIdx].question : ('Question ' + (qIdx + 1));
            $btn.prop('disabled', true).text('Generating...');
            $statusDiv.show().text('Generating image...').css('color', '#6c757d');
            $.ajax({
                url: config.wwwroot + '/mod/aiknowledgecheck/ajax.php',
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'generateimage',
                    sesskey: config.sesskey,
                    cmid: config.cmid,
                    prompt: promptText
                },
                timeout: 90000,
                success: function (resp) {
                    $btn.prop('disabled', false).text('Generate (5 credits)');
                    if (resp.ok && resp.imageDataUrl) {
                        $urlInput.val(resp.imageDataUrl);
                        $previewImg.attr('src', resp.imageDataUrl);
                        $previewDiv.show();
                        $statusDiv.text('Image generated!').css('color', '#28a745');
                    } else {
                        $statusDiv.text(resp.error || 'Generation failed').css('color', '#dc3545');
                    }
                },
                error: function () {
                    $btn.prop('disabled', false).text('Generate (5 credits)');
                    $statusDiv.text('Request failed. Please try again.').css('color', '#dc3545');
                }
            });
        });

        // ADD-KC-IMAGEGATE: live-preview image URL when pasted/changed.
        container.find('.kc-edit-image-url').on('change', function () {
            var url = $(this).val().trim();
            var $previewDiv = $(this).closest('.kc-edit-image-fields').find('.kc-edit-image-preview');
            var $previewImg = $previewDiv.find('.kc-edit-image-preview-img');
            if (url) {
                $previewImg.attr('src', url);
                $previewDiv.show();
            } else {
                $previewDiv.hide();
            }
        });

        // ADD-KC-MEDIAPER-Q (v1.5.120): Video checkbox — show/hide URL field and thumbnail.
        container.find('.kc-edit-video-enabled').on('change', function () {
            var $fields = $(this).closest('.kc-edit-videomedia').find('.kc-edit-video-fields');
            if ($(this).is(':checked')) {
                $fields.show();
            } else {
                $fields.hide();
            }
        });

        // ADD-KC-MEDIAPER-Q (v1.5.120): Video URL change — update YouTube thumbnail preview.
        container.find('.kc-edit-video-url').on('change', function () {
            var url    = $(this).val().trim();
            var $prev  = $(this).closest('.kc-edit-video-fields').find('.kc-edit-video-preview');
            var $thumb = $prev.find('img');
            var vid    = extractYouTubeId(url);
            if (vid) {
                $thumb.attr('src', 'https://img.youtube.com/vi/' + vid + '/hqdefault.jpg');
                $prev.show();
            } else {
                $thumb.attr('src', '');
                $prev.hide();
            }
        });

        // ADD-KC-MEDIAPER-Q (v1.5.120): Audio checkbox — show/hide URL field and player.
        container.find('.kc-edit-audio-enabled').on('change', function () {
            var $fields = $(this).closest('.kc-edit-audiomedia').find('.kc-edit-audio-fields');
            if ($(this).is(':checked')) {
                $fields.show();
            } else {
                $fields.hide();
            }
        });

        // ADD-KC-MEDIAPER-Q (v1.5.120): Audio URL change — refresh HTML5 player source.
        container.find('.kc-edit-audio-url').on('change', function () {
            var url    = $(this).val().trim();
            var $player = $(this).closest('.kc-edit-audio-fields').find('.kc-edit-audio-player');
            var $audio  = $player.find('audio');
            if (url) {
                $audio.find('source').remove();
                $audio.append('<source src="' + url.replace(/"/g, '&quot;') + '">');
                $audio[0] && $audio[0].load();
                $player.show();
            } else {
                $audio.find('source').remove();
                $player.hide();
            }
        });

        $('.kc-edit-option input[type="radio"]').on('change', function () {
            var $option = $(this).closest('.kc-edit-option');
            var $question = $(this).closest('.kc-edit-question');
            $question.find('.kc-edit-option').removeClass('kc-edit-option-correct');
            $option.addClass('kc-edit-option-correct');
        });
        
        $('.kc-btn-delete-question').on('click', function () {
            var idx = parseInt($(this).data('index'));
            if (quizData.length <= 1) {
                alert('Cannot delete the last question. You must have at least one question.');
                return;
            }
            if (confirm('Are you sure you want to delete Question ' + (idx + 1) + '?')) {
                quizData.splice(idx, 1);
                buildEditForms();
            }
        });

    }
    
    function escapeHtml(text) {
        if (!text) return '';
        return text.replace(/&/g, '&amp;')
                   .replace(/</g, '&lt;')
                   .replace(/>/g, '&gt;')
                   .replace(/"/g, '&quot;')
                   .replace(/'/g, '&#039;');
    }
    
    function escapeAttr(text) {
        if (!text) return '';
        return text.replace(/&/g, '&amp;')
                   .replace(/</g, '&lt;')
                   .replace(/>/g, '&gt;')
                   .replace(/"/g, '&quot;')
                   .replace(/'/g, '&#039;');
    }
    
    function saveEdits() {
        console.log('[KC] Saving edited questions');
        
        // Collect edited data from forms
        var editedQuestions = [];
        var hasErrors = false;
        
        $('#edit-questions-container .kc-edit-question').each(function () {
            var $q = $(this);
            var idx = parseInt($q.data('question-index'));
            
            var questionText = $q.find('.kc-edit-question-text').val().trim();
            if (!questionText) {
                hasErrors = true;
                alert('Question ' + (idx + 1) + ' text cannot be empty.');
                return false;
            }
            
            var options = [];
            var explanations = [];
            var correctAnswer = 0;
            
            var hasCorrectSelected = false;
            // FIX-KC-EDIT-SURVEY (v1.5.139): free-text questions have no options and no correct
            // answer, so option/correct-answer validation must not run for them.
            var isFreeText = ($q.attr('data-question-type') === 'freetext') ||
                             (quizData[idx] && quizData[idx].questionType === 'freetext');
            var isSurvey   = !!config.surveyMode;
            
            $q.find('.kc-edit-option').each(function (optIdx) {
                var optionText = $(this).find('.kc-edit-option-text').val().trim();
                var explanationText = $(this).find('.kc-edit-explanation-text').val().trim();
                
                if (!optionText) {
                    hasErrors = true;
                    alert('Question ' + (idx + 1) + ', Option ' + String.fromCharCode(65 + optIdx) + ' cannot be empty.');
                    return false;
                }
                
                options.push(optionText);
                explanations.push(explanationText);
                
                if ($(this).find('input[type="radio"]').is(':checked')) {
                    correctAnswer = optIdx;
                    hasCorrectSelected = true;
                }
            });
            
            if (hasErrors) return false;
            
            // Validate that a correct answer is selected. Skipped for free-text questions
            // (no options) and in survey mode (no correct answer by definition).
            if (!isFreeText && !isSurvey && !hasCorrectSelected) {
                hasErrors = true;
                alert('Question ' + (idx + 1) + ': Please select a correct answer.');
                return false;
            }
            
            editedQuestions.push({
                question: questionText,
                options: options,
                explanations: explanations,
                correctAnswer: correctAnswer,
                // FIX-KC-EDIT-SURVEY (v1.5.139): carry question type through the edit round
                // trip; it was omitted, so ajax.php fell back to its 'scale' default and every
                // free-text question was converted to multiple choice on save.
                questionType: isFreeText ? 'freetext' : ((quizData[idx] && quizData[idx].questionType) || 'scale'),
                audioData: ($('#voiceover-toggle').is(':checked') && quizData[idx]) ? quizData[idx].audioData : null,
                // FIX-KC-SAVEEDITS-TIMESTAMP (v1.5.111): Preserve timestamp_seconds,
                // mappingTopic, and mappingCriteria from the original quizData entry.
                // saveEdits() previously omitted these fields, so any save from the
                // Edit Questions section silently wiped them from quizData in memory.
                // On the next regeneration the server received timestamp_seconds=null,
                // the preserve step never fired, and the DB stored null for every
                // question — making Jump-to chapter-stamp links permanently disappear
                // until the teacher regenerated from a freshly-loaded page (no edits).
                mappingTopic:       quizData[idx] ? (quizData[idx].mappingTopic    || '') : '',
                mappingCriteria:    quizData[idx] ? (quizData[idx].mappingCriteria || '') : '',
                timestamp_seconds:  quizData[idx] ? (quizData[idx].timestamp_seconds !== undefined ? quizData[idx].timestamp_seconds : null) : null,
                // ADD-KC-IMAGEGATE (v1.5.115): Read per-question image from DOM; fall back
                // to existing quizData values if the DOM fields are not present.
                imageUrl:           $q.find('.kc-edit-image-url').length ? $q.find('.kc-edit-image-url').val().trim() : (quizData[idx] ? (quizData[idx].imageUrl || '') : ''),
                imageEnabled:       $q.find('.kc-edit-image-enabled').length ? $q.find('.kc-edit-image-enabled').is(':checked') : (quizData[idx] ? !!quizData[idx].imageEnabled : false),
                // ADD-KC-MEDIAPER-Q (v1.5.120): Read per-question video and audio from DOM.
                questionVideoUrl:     $q.find('.kc-edit-video-url').length ? $q.find('.kc-edit-video-url').val().trim() : (quizData[idx] ? (quizData[idx].questionVideoUrl || '') : ''),
                questionVideoEnabled: $q.find('.kc-edit-video-enabled').length ? $q.find('.kc-edit-video-enabled').is(':checked') : (quizData[idx] ? !!quizData[idx].questionVideoEnabled : false),
                questionAudioUrl:     $q.find('.kc-edit-audio-url').length ? $q.find('.kc-edit-audio-url').val().trim() : (quizData[idx] ? (quizData[idx].questionAudioUrl || '') : ''),
                questionAudioEnabled: $q.find('.kc-edit-audio-enabled').length ? $q.find('.kc-edit-audio-enabled').is(':checked') : (quizData[idx] ? !!quizData[idx].questionAudioEnabled : false)
            });
        });
        
        if (hasErrors) return;

        // FIX-KC-GUARD-SAVEEDITS: If no questions were collected and no validation errors
        // fired, the edit container must have been empty  -  abort rather than wiping the DB.
        if (editedQuestions.length === 0) {
            alert('No questions were found in the edit forms. Please reload the page and try again.');
            return;
        }
        
        // Detect whether any question content actually changed vs. what was loaded into the
        // edit form (originalQuizData). If nothing changed we should NOT regenerate TTS audio  - 
        // it is already valid and re-generating wastes credits (common after a failed regen
        // where the teacher clicks Save Changes without editing anything).
        var questionsContentChanged = false;
        if (!originalQuizData || editedQuestions.length !== originalQuizData.length) {
            questionsContentChanged = true;
        } else {
            for (var ci = 0; ci < editedQuestions.length; ci++) {
                var _eq = editedQuestions[ci];
                var _oq = originalQuizData[ci];
                if (_eq.question !== _oq.question ||
                    JSON.stringify(_eq.options) !== JSON.stringify(_oq.options) ||
                    JSON.stringify(_eq.explanations) !== JSON.stringify(_oq.explanations) ||
                    _eq.correctAnswer !== _oq.correctAnswer) {
                    questionsContentChanged = true;
                    break;
                }
            }
        }

        // Update quizData
        quizData = editedQuestions;
        
        // Show saving indicator
        $('#save-edits-btn').prop('disabled', true).html(
            '<svg class="kc-spinner" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg> Saving...'
        );
        
        // Save to database with proper async handling
        saveEditedQuestions(function (success) {
            if (success) {
                var voiceoverOn = $('#voiceover-toggle').is(':checked');
                // Only regenerate audio when question content actually changed.
                // If nothing changed (e.g., teacher hit Save after a failed regen
                // without editing), the existing TTS audio is still valid.
                if (voiceoverOn && questionsContentChanged) {
                    $('#save-edits-btn').html(
                        '<svg class="kc-spinner" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg> Generating voiceover...'
                    );
                    
                    regenerateAudioWithCallback(function (audioSuccess) {
                        $('#save-edits-btn').prop('disabled', false).html(
                            '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg> Save Changes'
                        );
                        
                        $('#kc-edit-section').hide();
                        $('#kc-ready-section').show();
                        
                        if (audioSuccess) {
                            $('#ready-summary').text(quizData.length + ' questions saved with updated voiceover!');
                        } else {
                            $('#ready-summary').text(quizData.length + ' questions saved. Voiceover generation failed - you can try regenerating later.');
                        }
                    });
                } else {
                    $('#save-edits-btn').prop('disabled', false).html(
                        '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg> Save Changes'
                    );
                    
                    $('#kc-edit-section').hide();
                    $('#kc-ready-section').show();
                    $('#ready-summary').text(quizData.length + ' questions saved successfully!');
                }
            } else {
                // Save failed
                $('#save-edits-btn').prop('disabled', false).html(
                    '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg> Save Changes'
                );
                alert('Failed to save questions. Please try again.');
            }
        });
    }
    
    // Save edited questions with callback for proper async handling
    function saveEditedQuestions(callback) {
        var questionsForDb = quizData.map(function (q) {
            // FIX-KC-EDIT-SURVEY (v1.5.139): this payload hardcoded options[0..3] and omitted
            // questionType, causing two silent data losses on every save from the editor:
            //   - ajax.php sets answer5 = null when options[4] is absent, deleting the 5th
            //     point of 5-point survey scales;
            //   - ajax.php defaults questiontype to 'scale' when the field is absent,
            //     converting free-text questions into blank multiple-choice questions.
            var qType = q.questionType || 'scale';
            var opts = [];
            if (qType !== 'freetext') {
                var srcOpts = q.options || [];
                for (var oi = 0; oi < srcOpts.length && oi < 5; oi++) {
                    opts.push({
                        text: srcOpts[oi],
                        explanation: q.explanations ? (q.explanations[oi] || '') : ''
                    });
                }
            }
            return {
                question: q.question,
                options: opts,
                questionType: qType,
                correctIndex: q.correctAnswer,
                audioData: q.audioData || null,
                mappingTopic: q.mappingTopic || '',
                mappingCriteria: q.mappingCriteria || '',
                // FIX-KC-TIMESTAMP-SAVE: preserve timestamp_seconds so "Show chapter
                // timestamp links" (Video Gate) buttons survive edit → save round-trip.
                timestamp_seconds: (q.timestamp_seconds !== undefined && q.timestamp_seconds !== null) ? q.timestamp_seconds : null
            };
        });
        
        $.ajax({
            url: config.wwwroot + '/mod/aiknowledgecheck/ajax.php',
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'savequestions',
                sesskey: config.sesskey,
                cmid: config.cmid,
                questions: JSON.stringify(questionsForDb),
                voiceoverEnabled: $('#voiceover-toggle').is(':checked') ? 1 : 0,
                voiceLanguage: $('#voice-language').val() || '',
                voiceGender: $('#voice-gender').val() || '',
                voiceStyle: $('#voice-style').val() || ''
            },
            success: function (response) {
                if (response.ok) {
                    console.log('[KC] Questions saved:', response.saved);
                    callback(true);
                } else {
                    console.error('[KC] Save failed:', response.error);
                    callback(false);
                }
            },
            error: function (xhr, status, error) {
                console.error('[KC] Save request failed:', status, error);
                callback(false);
            }
        });
    }
    
    // Regenerate audio with callback
    function regenerateAudioWithCallback(callback) {
        var voiceLanguage = $('#voice-language').val() || 'en-AU';
        var voiceId = $('#voice-style').val() || 'Aoede';
        
        var questionsForApi = quizData.map(function (q) {
            return {
                id: q.id,
                question: q.question,
                options: q.options,
                explanations: q.explanations,
                correctAnswer: q.correctAnswer
            };
        });
        
        $.ajax({
            url: config.wwwroot + '/mod/aiknowledgecheck/ajax.php',
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'regenerateaudio',
                sesskey: config.sesskey,
                cmid: config.cmid,
                questions: JSON.stringify(questionsForApi),
                voiceLanguage: voiceLanguage,
                voiceId: voiceId
            },
            timeout: 120000,
            success: function (response) {
                if (response.ok && response.questions) {
                    console.log('[KC] Audio regenerated for', response.questions.length, 'questions');
                    
                    // Update quizData with new audio
                    for (var i = 0; i < response.questions.length; i++) {
                        if (quizData[i] && response.questions[i].audioData) {
                            quizData[i].audioData = response.questions[i].audioData;
                        }
                    }
                    
                    // Save updated audio to database with proper async handling
                    saveEditedQuestions(function (saveSuccess) {
                        if (saveSuccess) {
                            console.log('[KC] Audio data saved to database');
                            callback(true);
                        } else {
                            console.error('[KC] Failed to save audio data to database');
                            callback(false);
                        }
                    });
                } else {
                    console.error('[KC] Audio regeneration failed:', response.error);
                    callback(false);
                }
            },
            error: function (xhr, status, error) {
                console.error('[KC] Audio regeneration request failed:', status, error);
                callback(false);
            }
        });
    }
    
    function cancelEdits() {
        if (confirm('Discard all changes?')) {
            if (originalQuizData) {
                quizData = originalQuizData;
            }
            $('#kc-edit-section').hide();
            $('#kc-ready-section').show();
        }
    }

    function openSettingsModal() {
        console.log('[KC] Opening settings modal');
        $('#settings-voice-language').val($('#voice-language').val() || 'en-AU');
        var voiceoverEnabled = $('#voiceover-toggle').is(':checked');
        $('#settings-voiceover-toggle').prop('checked', voiceoverEnabled);
        if (voiceoverEnabled) {
            $('#settings-voice-options').show();
        } else {
            $('#settings-voice-options').hide();
        }
        var currentGender = $('#voice-gender').val() || 'female';
        var currentStyle = $('#voice-style').val() || 'Aoede';
        $('#settings-voice-gender').val(currentGender);
        var $style = $('#settings-voice-style');
        $style.empty();
        if (currentGender === 'female') {
            $style.append($('<option>').val('Aoede').text('Aoede (warm, friendly)'));
            $style.append($('<option>').val('Kore').text('Kore (clear, professional)'));
            $style.append($('<option>').val('Leda').text('Leda (soft, nurturing)'));
            $style.append($('<option>').val('Zephyr').text('Zephyr (energetic, youthful)'));
        } else {
            $style.append($('<option>').val('Puck').text('Puck (friendly, casual)'));
            $style.append($('<option>').val('Charon').text('Charon (deep, authoritative)'));
            $style.append($('<option>').val('Fenrir').text('Fenrir (warm, mature)'));
            $style.append($('<option>').val('Orus').text('Orus (clear, professional)'));
        }
        $style.val(currentStyle);
        updateSettingsWarning();
        $('#kc-settings-overlay').fadeIn(200);
    }

    function closeSettingsModal() {
        $('#kc-settings-overlay').fadeOut(200);
    }

    function saveSettings() {
        console.log('[KC] Saving settings');
        var newLanguage = $('#settings-voice-language').val();
        var newVoiceoverEnabled = $('#settings-voiceover-toggle').is(':checked');
        var newGender = $('#settings-voice-gender').val();
        var newStyle = $('#settings-voice-style').val();

        var oldLanguage = $('#voice-language').val();
        var oldVoiceoverEnabled = $('#voiceover-toggle').is(':checked');

        $('#voice-language').val(newLanguage);
        $('#voiceover-toggle').prop('checked', newVoiceoverEnabled);
        if (newVoiceoverEnabled) {
            $('#voice-settings-section').show();
        } else {
            $('#voice-settings-section').hide();
        }
        $('#voice-gender').val(newGender);
        handleGenderChange();
        setTimeout(function () {
            $('#voice-style').val(newStyle);
        }, 50);

        closeSettingsModal();

        var editInfoEl = $('.kc-edit-info');
        var origInfo = editInfoEl.text();

        $('#save-edits-btn').prop('disabled', true);
        $('#cancel-edits-btn').prop('disabled', true);
        $('#edit-settings-btn').prop('disabled', true);

        var languageChanged = (newLanguage !== oldLanguage);
        var voiceoverTurnedOff = (oldVoiceoverEnabled && !newVoiceoverEnabled);
        var voiceoverTurnedOn = (!oldVoiceoverEnabled && newVoiceoverEnabled);

        // Always persist voice settings to database first
        $.ajax({
            url: config.wwwroot + '/mod/aiknowledgecheck/ajax.php',
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'savevoicesettings',
                sesskey: config.sesskey,
                cmid: config.cmid,
                voiceoverEnabled: newVoiceoverEnabled ? 1 : 0,
                voiceLanguage: newLanguage,
                voiceGender: newGender,
                voiceStyle: newStyle
            },
            success: function () {
                console.log('[KC] Voice settings saved to database');
            },
            error: function () {
                console.error('[KC] Failed to save voice settings to database');
            }
        });

        if (voiceoverTurnedOff) {
            // Voiceover turned OFF: strip audio from quizData and save, no AI call needed
            editInfoEl.html('Saving settings... Removing voiceover audio.');

            for (var i = 0; i < quizData.length; i++) {
                quizData[i].audioData = null;
            }
            buildEditForms();
            saveEditedQuestions(function (saveSuccess) {
                enableSettingsButtons(origInfo, editInfoEl);
                if (saveSuccess) {
                    editInfoEl.text('Voiceover disabled and audio removed.');
                    setTimeout(function () { editInfoEl.text(origInfo); }, 3000);
                } else {
                    alert('Failed to save. Please click Save Changes.');
                }
            });
        } else if (languageChanged) {
            // Language changed: regenerate questions via OpenAI (costs credits)
            editInfoEl.html(
                '<svg class="kc-spinner" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: middle; margin-right: 6px;"><circle cx="12" cy="12" r="10"/></svg>' +
                'Regenerating questions in new language... This may take a moment.'
            );

            var currentQuestions = [];
            $('#edit-questions-container .kc-edit-question').each(function () {
                var $q = $(this);
                var questionText = $q.find('.kc-edit-question-text').val().trim();
                var options = [];
                var explanations = [];
                var correctAnswer = 0;
                $q.find('.kc-edit-option').each(function (optIdx) {
                    options.push($(this).find('.kc-edit-option-text').val().trim());
                    explanations.push($(this).find('.kc-edit-explanation-text').val().trim());
                    if ($(this).find('input[type="radio"]').is(':checked')) {
                        correctAnswer = optIdx;
                    }
                });
                // FIX-KC-REGEN-PAYLOAD (v1.5.81): use {text, explanation} object format
                // and correctIndex to match the API's expected input format.
                currentQuestions.push({
                    type: 'mcq',
                    question: questionText,
                    options: [
                        { text: options[0] || '', explanation: explanations[0] || '' },
                        { text: options[1] || '', explanation: explanations[1] || '' },
                        { text: options[2] || '', explanation: explanations[2] || '' },
                        { text: options[3] || '', explanation: explanations[3] || '' }
                    ],
                    correctIndex: correctAnswer
                });
            });

            if (currentQuestions.length === 0) {
                currentQuestions = quizData.map(function (q) {
                    // FIX-KC-EDIT-SURVEY (v1.5.139): same fix as saveEditedQuestions — send
                    // every option the question has (up to 5) and carry questionType.
                    var qType2 = q.questionType || 'scale';
                    var opts2 = [];
                    if (qType2 !== 'freetext') {
                        var srcOpts2 = q.options || [];
                        for (var oj = 0; oj < srcOpts2.length && oj < 5; oj++) {
                            opts2.push({
                                text: srcOpts2[oj] || '',
                                explanation: (q.explanations && q.explanations[oj]) || ''
                            });
                        }
                    }
                    return {
                        type: q.type || 'mcq',
                        question: q.question,
                        options: opts2,
                        questionType: qType2,
                        correctIndex: q.correctAnswer
                    };
                });
            }

            $.ajax({
                url: config.wwwroot + '/mod/aiknowledgecheck/ajax.php',
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'regeneratewithsettings',
                    sesskey: config.sesskey,
                    cmid: config.cmid,
                    questions: JSON.stringify(currentQuestions),
                    voiceLanguage: newLanguage,
                    voiceoverEnabled: newVoiceoverEnabled ? 1 : 0,
                    voiceGender: newGender,
                    voiceId: newStyle
                },
                timeout: 180000,
                success: function (response) {
                    function applySettingsQuestions(questions) {
                        // FIX-KC-REGEN-STORE (v1.5.81): unpack {text,explanation} API format
                        // to KC's flat internal format (same fix as regenerateinstructions).
                        // FIX-KC-SETTINGS-TIMESTAMP (v1.5.104): Add index i so we can fall back
                        // to the original quizData[i].timestamp_seconds if the server omits the
                        // field — mirrors the double-fallback in the regenerateinstructions mapper.
                        var preRegenQuizData = quizData.slice();
                        quizData = questions.map(function (q, i) {
                            var opts = Array.isArray(q.options) ? q.options : [];
                            var isObjOpts = opts.length > 0 && typeof opts[0] === 'object' && opts[0] !== null;
                            // FIX-KC-REGEN-TIMESTAMP-NULL (v1.5.109): Use != null so an explicit
                            // null response also triggers the fallback to the original snapshot.
                            var preservedTs = (q.timestamp_seconds != null)
                                ? q.timestamp_seconds
                                : (preRegenQuizData[i] && preRegenQuizData[i].timestamp_seconds != null
                                    ? preRegenQuizData[i].timestamp_seconds : null);
                            return {
                                type: q.type || 'mcq',
                                question: q.question,
                                options: isObjOpts ? opts.map(function (o) { return o.text || ''; }) : opts,
                                explanations: isObjOpts ? opts.map(function (o) { return o.explanation || ''; }) : (q.explanations || []),
                                correctAnswer: q.correctIndex !== undefined ? q.correctIndex : (q.correctAnswer || 0),
                                audioData: newVoiceoverEnabled ? (q.audioData || null) : null,
                                mappingTopic: q.mappingTopic || '',
                                mappingCriteria: q.mappingCriteria || '',
                                timestamp_seconds: preservedTs !== undefined ? preservedTs : null,
                                // ADD-KC-MEDIAPER-Q (v1.5.120): Preserve all teacher-configured
                                // per-question media across settings regeneration. The AI never
                                // returns these fields so we carry them over from preRegenQuizData.
                                // Also fixes the pre-existing bug where imageUrl/imageEnabled were
                                // silently dropped on every settings-triggered regeneration.
                                imageUrl:             preRegenQuizData[i] ? (preRegenQuizData[i].imageUrl             || '') : '',
                                imageEnabled:         preRegenQuizData[i] ? !!preRegenQuizData[i].imageEnabled                : false,
                                questionVideoUrl:     preRegenQuizData[i] ? (preRegenQuizData[i].questionVideoUrl     || '') : '',
                                questionVideoEnabled: preRegenQuizData[i] ? !!preRegenQuizData[i].questionVideoEnabled         : false,
                                questionAudioUrl:     preRegenQuizData[i] ? (preRegenQuizData[i].questionAudioUrl     || '') : '',
                                questionAudioEnabled: preRegenQuizData[i] ? !!preRegenQuizData[i].questionAudioEnabled         : false
                            };
                        });
                        buildEditForms();
                        saveEditedQuestions(function (saveSuccess) {
                            enableSettingsButtons(origInfo, editInfoEl);
                            if (saveSuccess) {
                                editInfoEl.text('Questions regenerated and saved with new settings!');
                                setTimeout(function () { editInfoEl.text(origInfo); }, 3000);
                            } else {
                                alert('Questions regenerated but failed to save. Please click Save Changes.');
                            }
                        });
                    }
                    if (response.ok && response.jobId) {
                        pollRegenJob(response.jobId, null, '', function (completed) {
                            var qs = completed.questions || [];
                            if (qs.length === 0) {
                                enableSettingsButtons(origInfo, editInfoEl);
                                alert('Regeneration completed but returned 0 questions. Please try again.');
                                return;
                            }
                            applySettingsQuestions(qs);
                        }, function (errMsg) {
                            enableSettingsButtons(origInfo, editInfoEl);
                            alert('Regeneration failed: ' + errMsg);
                        });
                    } else if (response.ok && response.questions) {
                        console.log('[KC] Settings regeneration complete:', response.questions.length, 'questions');
                        applySettingsQuestions(response.questions);
                    } else {
                        console.error('[KC] Settings regeneration failed:', response.error);
                        enableSettingsButtons(origInfo, editInfoEl);
                        alert('Regeneration failed: ' + (response.error || 'Unknown error'));
                    }
                },
                error: function (xhr, status, error) {
                    console.error('[KC] Settings regeneration request failed:', status, error);
                    enableSettingsButtons(origInfo, editInfoEl);
                    alert('Request failed. Please try again.');
                }
            });
        } else if (voiceoverTurnedOn) {
            // Voiceover turned ON: generate audio for existing questions
            editInfoEl.html(
                '<svg class="kc-spinner" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: middle; margin-right: 6px;"><circle cx="12" cy="12" r="10"/></svg>' +
                'Generating voiceover audio...'
            );
            regenerateAudioWithCallback(function (audioSuccess) {
                enableSettingsButtons(origInfo, editInfoEl);
                if (audioSuccess) {
                    editInfoEl.text('Voiceover audio generated successfully!');
                } else {
                    editInfoEl.text('Voiceover generation failed. You can try again later.');
                }
                setTimeout(function () { editInfoEl.text(origInfo); }, 3000);
            });
        } else {
            // Only voice style/gender changed (same language, voiceover still on): regenerate audio only
            if (newVoiceoverEnabled) {
                editInfoEl.html(
                    '<svg class="kc-spinner" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: middle; margin-right: 6px;"><circle cx="12" cy="12" r="10"/></svg>' +
                    'Updating voiceover with new voice...'
                );
                regenerateAudioWithCallback(function (audioSuccess) {
                    enableSettingsButtons(origInfo, editInfoEl);
                    if (audioSuccess) {
                        editInfoEl.text('Voice settings updated!');
                    } else {
                        editInfoEl.text('Audio update failed. You can try again later.');
                    }
                    setTimeout(function () { editInfoEl.text(origInfo); }, 3000);
                });
            } else {
                // Nothing changed that needs processing
                enableSettingsButtons(origInfo, editInfoEl);
                editInfoEl.text('Settings saved.');
                setTimeout(function () { editInfoEl.text(origInfo); }, 3000);
            }
        }
    }

    function updateRegenCountDisplay() {
        var remaining = Math.max(0, 3 - regenerationCount);
        var countText = '';
        if (regenerationCount === 0) {
            countText = '3 free regenerations remaining';
        } else if (remaining > 0) {
            countText = remaining + ' free regeneration' + (remaining !== 1 ? 's' : '') + ' remaining';
        } else {
            countText = 'Free regenerations used. Next regeneration will use credits.';
        }
        $('#ready-regen-count').text(countText).toggleClass('kc-regen-warning', remaining === 0);
        $('#edit-regen-count').text(countText).toggleClass('kc-regen-warning', remaining === 0);
    }

    // FIX-KC-REGEN-ASYNC (v1.5.89): The external API changed to an async job model for
    // regenerateinstructions and regeneratewithsettings: it returns {ok:true, jobId:"..."}
    // immediately rather than waiting for questions. All three regen handlers previously only
    // checked response.questions, so they always hit the else-branch, showed "Retrying…", and
    // then gave up with "The AI service is temporarily busy." Fix: poll the status action using
    // the same /api/knowledgecheck-status/{jobId} endpoint already used by initial generation.
    //
    // jobId       - the job ID returned by the API
    // $progressBtn - optional jQuery button to show live progress on (null = don't update)
    // spinnerSvg  - spinner HTML prefix for the progress label
    // onComplete  - callback(response) called when status==='completed' with the full response
    // onError     - callback(errorMessage) called on failure or timeout
    function pollRegenJob(jobId, $progressBtn, spinnerSvg, onComplete, onError) {
        var polls = 0;
        var MAX_POLLS = 90; // 90 × 2s = 3 minutes max
        var regenPollInterval = setInterval(function () {
            polls++;
            if (polls > MAX_POLLS) {
                clearInterval(regenPollInterval);
                onError('Timed out waiting for regeneration. Please try again.');
                return;
            }
            $.ajax({
                url: config.wwwroot + '/mod/aiknowledgecheck/ajax.php',
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'status',
                    sesskey: config.sesskey,
                    cmid: config.cmid,
                    jobId: jobId
                },
                timeout: 15000,
                success: function (response) {
                    if (!response.ok) {
                        clearInterval(regenPollInterval);
                        onError(response.error || 'Regeneration failed');
                        return;
                    }
                    if ($progressBtn && response.progress !== undefined) {
                        $progressBtn.html(spinnerSvg + 'Regenerating\u2026 ' + Math.round(response.progress) + '%');
                    }
                    if (response.status === 'completed') {
                        clearInterval(regenPollInterval);
                        onComplete(response);
                    } else if (response.status === 'failed') {
                        clearInterval(regenPollInterval);
                        onError(response.error || 'Regeneration failed on the server');
                    }
                    // 'processing' — keep polling
                },
                error: function () {
                    // Ignore individual poll failures — keep the interval running
                }
            });
        }, 2000);
    }

    function handleRegenerateWithInstructions(source) {
        var extraInstructions = source === 'ready' 
            ? $('#ready-extra-instructions').val() 
            : $('#edit-extra-instructions').val();
        var $btn = source === 'ready' ? $('#ready-regenerate-btn') : $('#edit-regenerate-btn');

        if (!quizData || quizData.length === 0) {
            alert('No questions to regenerate. Please generate questions first.');
            return;
        }

        var isFree = regenerationCount < 3;
        if (!isFree) {
            var voiceoverOn = $('#voiceover-toggle').is(':checked') || !!config.voiceoverEnabled;
            var creditsNeeded = voiceoverOn ? quizData.length * 2 : quizData.length;
            if (!confirm('You have used all 3 free regenerations.\n\nThis regeneration will cost ' + creditsNeeded + ' credits.\n\nDo you want to continue?')) {
                return;
            }
        }

        // FIX-KC-REGEN-BATCH (v1.5.88): Replace slow sequential per-question requests with a
        // single batch call. The server's regenerateinstructions endpoint calls Gemini once for
        // ALL questions — sending them one-at-a-time multiplied latency and caused the "Q{n} busy
        // — retrying…" stall (each retry waited 10 seconds). A batch call is both faster and
        // simpler: one round-trip, no per-question delays.
        var regenBtnRestoreHtml =
            '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 4px; vertical-align: middle;">' +
                '<polyline points="1 4 1 10 7 10"/>' +
                '<polyline points="23 20 23 14 17 14"/>' +
                '<path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10m22 4l-4.64 4.36A9 9 0 0 1 3.51 15"/>' +
            '</svg>Regenerate Questions';

        var spinnerSvg =
            '<svg class="kc-spinner" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: middle; margin-right: 6px;"><circle cx="12" cy="12" r="10"/></svg>';

        function restoreBtn() {
            $btn.prop('disabled', false).html(regenBtnRestoreHtml);
        }

        var voiceoverEnabled = $('#voiceover-toggle').is(':checked') || !!config.voiceoverEnabled;
        var total = quizData.length;

        $btn.prop('disabled', true).html(
            spinnerSvg + 'Regenerating ' + total + ' question' + (total !== 1 ? 's' : '') + '\u2026'
        );

        // Build batch payload — all questions in one array.
        // FIX-KC-REGEN-TIMESTAMP (v1.5.92): Include timestamp_seconds in the payload so the
        // server can preserve it in the response. Without this the server always receives
        // undefined and the preservation branch never runs, dropping Jump-to links after regen.
        var allQuestions = quizData.map(function (q0) {
            return {
                type: q0.type || 'mcq',
                question: q0.question,
                options: [
                    { text: (q0.options && q0.options[0]) || '', explanation: (q0.explanations && q0.explanations[0]) || '' },
                    { text: (q0.options && q0.options[1]) || '', explanation: (q0.explanations && q0.explanations[1]) || '' },
                    { text: (q0.options && q0.options[2]) || '', explanation: (q0.explanations && q0.explanations[2]) || '' },
                    { text: (q0.options && q0.options[3]) || '', explanation: (q0.explanations && q0.explanations[3]) || '' }
                ],
                correctIndex: q0.correctAnswer,
                mappingTopic: q0.mappingTopic || '',
                mappingCriteria: q0.mappingCriteria || '',
                timestamp_seconds: (q0.timestamp_seconds !== undefined && q0.timestamp_seconds !== null) ? q0.timestamp_seconds : null
            };
        });

        function doBatchRequest(retriesLeft) {
            $.ajax({
                url: config.wwwroot + '/mod/aiknowledgecheck/ajax.php',
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'regenerateinstructions',
                    sesskey: config.sesskey,
                    cmid: config.cmid,
                    questions: JSON.stringify(allQuestions),
                    extraInstructions: extraInstructions || '',
                    voiceLanguage: $('#voice-language').val() || 'en-AU',
                    voiceoverEnabled: voiceoverEnabled ? 1 : 0,
                    voiceGender: $('#voice-gender').val() || 'female',
                    voiceId: $('#voice-style').val() || 'Aoede'
                },
                timeout: 180000, // 3 min: server runs Gemini + optional TTS in one shot
                success: function (response) {
                    function applyBatchQuestions(questions) {
                        var newQuizData = quizData.slice();
                        for (var i = 0; i < questions.length && i < newQuizData.length; i++) {
                            var rq = questions[i];
                            var rqOpts = Array.isArray(rq.options) ? rq.options : [];
                            var rqIsObj = rqOpts.length > 0 && typeof rqOpts[0] === 'object' && rqOpts[0] !== null;
                            // FIX-KC-REGEN-TIMESTAMP (v1.5.92): Preserve timestamp_seconds from
                            // server response so Jump-to links survive batch regeneration.
                            // Also fall back to the original quizData entry in case server omits it.
                            // FIX-KC-REGEN-TIMESTAMP-NULL (v1.5.109): Use != null (not !== undefined)
                            // so an explicit null in the server response also triggers the fallback.
                            // rq.timestamp_seconds !== undefined was TRUE for null, meaning a null
                            // server response silently overwrote the valid original timestamp.
                            var preservedTs = (rq.timestamp_seconds != null)
                                ? rq.timestamp_seconds
                                : (quizData[i] && quizData[i].timestamp_seconds != null ? quizData[i].timestamp_seconds : null);
                            newQuizData[i] = {
                                type: rq.type || 'mcq',
                                question: rq.question,
                                options: rqIsObj ? rqOpts.map(function (o) { return o.text || ''; }) : rqOpts,
                                explanations: rqIsObj ? rqOpts.map(function (o) { return o.explanation || ''; }) : (rq.explanations || []),
                                correctAnswer: rq.correctIndex !== undefined ? rq.correctIndex : (rq.correctAnswer || 0),
                                audioData: voiceoverEnabled ? (rq.audioData || null) : null,
                                mappingTopic: rq.mappingTopic || '',
                                mappingCriteria: rq.mappingCriteria || '',
                                timestamp_seconds: preservedTs !== undefined ? preservedTs : null,
                                // ADD-KC-MEDIAPER-Q (v1.5.120): Preserve all teacher-configured
                                // per-question media across batch regeneration. The AI never returns
                                // these fields so we carry them forward from the pre-regen quizData[i].
                                // Also fixes the pre-existing bug where imageUrl/imageEnabled were
                                // silently dropped on every batch-triggered regeneration.
                                imageUrl:             quizData[i] ? (quizData[i].imageUrl             || '') : '',
                                imageEnabled:         quizData[i] ? !!quizData[i].imageEnabled                : false,
                                questionVideoUrl:     quizData[i] ? (quizData[i].questionVideoUrl     || '') : '',
                                questionVideoEnabled: quizData[i] ? !!quizData[i].questionVideoEnabled         : false,
                                questionAudioUrl:     quizData[i] ? (quizData[i].questionAudioUrl     || '') : '',
                                questionAudioEnabled: quizData[i] ? !!quizData[i].questionAudioEnabled         : false
                            };
                        }
                        quizData = newQuizData;
                        regenerationCount = regenerationCount + 1;
                        updateRegenCountDisplay();
                        $('#ready-extra-instructions').val(extraInstructions || '');
                        $('#edit-extra-instructions').val(extraInstructions || '');
                        saveQuestionsToDatabase();
                        fetchCredits();
                        if (source === 'edit') { buildEditForms(); }
                        if (source === 'ready') { $('#ready-summary').text(total + ' questions regenerated!'); }
                        restoreBtn();
                        var msg = isFree
                            ? 'Questions regenerated successfully! (Free regeneration)'
                            : 'Questions regenerated successfully! Credits have been charged.';
                        alert(msg);
                    }
                    if (response.ok && response.jobId) {
                        pollRegenJob(response.jobId, $btn, spinnerSvg, function (completed) {
                            var qs = completed.questions || [];
                            if (qs.length === 0) {
                                restoreBtn();
                                alert('Regeneration completed but returned 0 questions. Please try again.');
                                return;
                            }
                            applyBatchQuestions(qs);
                        }, function (errMsg) {
                            restoreBtn();
                            alert('Regeneration failed: ' + errMsg + '\n\nPlease try again.');
                        });
                    } else if (response.ok && response.questions && response.questions.length > 0) {
                        applyBatchQuestions(response.questions);
                    } else {
                        var errorMsg = response.error || 'Unknown error';
                        console.warn('[KC] Regen batch error (retriesLeft=' + retriesLeft + '):', errorMsg);
                        if (retriesLeft > 0) {
                            $btn.html(spinnerSvg + 'Retrying\u2026');
                            setTimeout(function () { doBatchRequest(retriesLeft - 1); }, 2000);
                        } else {
                            restoreBtn();
                            alert('Regeneration failed: ' + errorMsg + '\n\nPlease try again.');
                        }
                    }
                },
                error: function (xhr, status, err) {
                    console.warn('[KC] Regen batch request failed (retriesLeft=' + retriesLeft + '):', status, err);
                    if (retriesLeft > 0) {
                        $btn.html(spinnerSvg + 'Retrying\u2026');
                        setTimeout(function () { doBatchRequest(retriesLeft - 1); }, 2000);
                    } else {
                        restoreBtn();
                        alert('Regeneration failed (connection error). Please try again.');
                    }
                }
            });
        }

        doBatchRequest(1); // Up to 2 total attempts (1 retry).
    }

    // FIX-KC-PER-QUESTION-REGEN (v1.5.77): Regenerates a single question at index idx.
    // Sends only that one question to the regenerateinstructions endpoint so the AI focuses on it.
    // Replaces just that entry in quizData, saves to DB, and rebuilds the edit form.
    function handleKCSingleRegenerate(idx, $btn) {
        if (!quizData || idx < 0 || idx >= quizData.length) return;

        var isFree = regenerationCount < 3;
        if (!isFree) {
            var voiceoverOn = $('#voiceover-toggle').is(':checked') || !!config.voiceoverEnabled;
            var creditsNeeded = voiceoverOn ? 2 : 1;
            if (!confirm('You have used all 3 free regenerations.\n\nRegenerating this question will cost ' + creditsNeeded + ' credit' + (creditsNeeded !== 1 ? 's' : '') + '.\n\nDo you want to continue?')) {
                return;
            }
        }

        var origHtml = $btn.html();
        $btn.prop('disabled', true).html(
            '<svg class="kc-spinner" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg>'
        );

        // FIX-KC-SINGLEREGEN-PAYLOAD (v1.5.82): Send {text,explanation} object format with
        // correctIndex and type, matching what the API expects (same fix as was applied to
        // handleRegenerateWithInstructions in v1.5.81 but not yet here).
        // FIX-KC-REGEN-TIMESTAMP (v1.5.92): Include timestamp_seconds so server can preserve it.
        var q0 = quizData[idx];
        var singleQuestion = [{
            type: q0.type || 'mcq',
            question: q0.question,
            options: [
                { text: (q0.options && q0.options[0]) || '', explanation: (q0.explanations && q0.explanations[0]) || '' },
                { text: (q0.options && q0.options[1]) || '', explanation: (q0.explanations && q0.explanations[1]) || '' },
                { text: (q0.options && q0.options[2]) || '', explanation: (q0.explanations && q0.explanations[2]) || '' },
                { text: (q0.options && q0.options[3]) || '', explanation: (q0.explanations && q0.explanations[3]) || '' }
            ],
            correctIndex: q0.correctAnswer,
            timestamp_seconds: (q0.timestamp_seconds !== undefined && q0.timestamp_seconds !== null) ? q0.timestamp_seconds : null
        }];

        var voiceoverEnabled = $('#voiceover-toggle').is(':checked') || !!config.voiceoverEnabled;
        var extraInstructions = $('#edit-extra-instructions').val() || '';

        $.ajax({
            url: config.wwwroot + '/mod/aiknowledgecheck/ajax.php',
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'regenerateinstructions',
                sesskey: config.sesskey,
                cmid: config.cmid,
                questions: JSON.stringify(singleQuestion),
                extraInstructions: extraInstructions,
                voiceLanguage: $('#voice-language').val() || 'en-AU',
                voiceoverEnabled: voiceoverEnabled ? 1 : 0,
                voiceGender: $('#voice-gender').val() || 'female',
                voiceId: $('#voice-style').val() || 'Aoede'
            },
            timeout: 120000,
            success: function (response) {
                function applySingleQuestion(rq) {
                    // FIX-KC-SINGLEREGEN-STORE (v1.5.82): Unpack API response from
                    // {text,explanation} object format to KC's flat internal format.
                    // FIX-KC-REGEN-TIMESTAMP (v1.5.92): Preserve timestamp_seconds so Jump-to
                    // links survive single-question regeneration. Fall back to original if server
                    // omits the field.
                    var rqOpts = Array.isArray(rq.options) ? rq.options : [];
                    var rqIsObj = rqOpts.length > 0 && typeof rqOpts[0] === 'object' && rqOpts[0] !== null;
                    var origTs = quizData[idx] ? quizData[idx].timestamp_seconds : undefined;
                    // ADD-KC-MEDIAPER-Q (v1.5.120): Snapshot the pre-regen entry so we can
                    // carry teacher-configured per-question media through the replacement.
                    var origSingle = quizData[idx] || null;
                    // FIX-KC-REGEN-TIMESTAMP-NULL (v1.5.109): Use != null so an explicit null
                    // response also falls back to the original quizData timestamp (same fix as
                    // applyBatchQuestions above — !== undefined was TRUE for null, clobbering it).
                    var singleTs = (rq.timestamp_seconds != null) ? rq.timestamp_seconds : (origTs != null ? origTs : null);
                    quizData[idx] = {
                        type: rq.type || 'mcq',
                        question: rq.question,
                        options: rqIsObj ? rqOpts.map(function (o) { return o.text || ''; }) : rqOpts,
                        explanations: rqIsObj ? rqOpts.map(function (o) { return o.explanation || ''; }) : (rq.explanations || []),
                        correctAnswer: rq.correctIndex !== undefined ? rq.correctIndex : (rq.correctAnswer || 0),
                        audioData: voiceoverEnabled ? (rq.audioData || null) : null,
                        mappingTopic: rq.mappingTopic || '',
                        mappingCriteria: rq.mappingCriteria || '',
                        timestamp_seconds: singleTs !== undefined ? singleTs : null,
                        // ADD-KC-MEDIAPER-Q (v1.5.120): Preserve all teacher-configured
                        // per-question media across single-question regeneration. The AI never
                        // returns these fields so we carry them from the pre-regen snapshot.
                        // Also fixes the pre-existing bug where imageUrl/imageEnabled were
                        // silently dropped on every single-question regen.
                        imageUrl:             origSingle ? (origSingle.imageUrl             || '') : '',
                        imageEnabled:         origSingle ? !!origSingle.imageEnabled                : false,
                        questionVideoUrl:     origSingle ? (origSingle.questionVideoUrl     || '') : '',
                        questionVideoEnabled: origSingle ? !!origSingle.questionVideoEnabled         : false,
                        questionAudioUrl:     origSingle ? (origSingle.questionAudioUrl     || '') : '',
                        questionAudioEnabled: origSingle ? !!origSingle.questionAudioEnabled         : false
                    };
                    regenerationCount = regenerationCount + 1;
                    updateRegenCountDisplay();
                    saveQuestionsToDatabase();
                    fetchCredits();
                    buildEditForms();
                    // buildEditForms() recreates the DOM so $btn is stale — no need to re-enable
                }
                if (response.ok && response.jobId) {
                    pollRegenJob(response.jobId, null, '', function (completed) {
                        var qs = completed.questions || [];
                        if (qs.length === 0) {
                            alert('Regeneration completed but returned 0 questions. Please try again.');
                            $btn.prop('disabled', false).html(origHtml);
                            return;
                        }
                        applySingleQuestion(qs[0]);
                    }, function (errMsg) {
                        alert('Regeneration failed: ' + errMsg);
                        $btn.prop('disabled', false).html(origHtml);
                    });
                } else if (response.ok && response.questions && response.questions.length > 0) {
                    applySingleQuestion(response.questions[0]);
                } else {
                    console.error('[KC] Single-question regeneration failed:', response.error);
                    alert('Regeneration failed: ' + (response.error || 'Unknown error'));
                    $btn.prop('disabled', false).html(origHtml);
                }
            },
            error: function (xhr, status, error) {
                console.error('[KC] Single-question regeneration request failed:', status, error);
                alert('Request failed. Please try again.');
                $btn.prop('disabled', false).html(origHtml);
            }
        });
    }

    function enableSettingsButtons(origInfo, editInfoEl) {
        $('#save-edits-btn').prop('disabled', false);
        $('#cancel-edits-btn').prop('disabled', false);
        $('#edit-settings-btn').prop('disabled', false);
        $('#settings-save-btn').prop('disabled', false).html(
            '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right: 4px; vertical-align: middle;"><polyline points="23 4 11.5 15.5 6 10"/></svg> Save Settings'
        );
    }

    function updateSettingsWarning() {
        var voiceoverOn = $('#settings-voiceover-toggle').is(':checked');
        var newLang = $('#settings-voice-language').val();
        var oldLang = $('#voice-language').val();
        var langChanged = (newLang !== oldLang);
        var msg = '';
        if (langChanged) {
            msg = 'Changing language will regenerate questions and uses credits.';
        } else if (voiceoverOn) {
            msg = 'Voice settings will be saved. Audio will be updated if voice changed.';
        } else {
            msg = 'Voiceover is disabled. No audio will be generated.';
        }
        $('#settings-warning-msg').text(msg);
    }

    return {
        init: init
    };
});
