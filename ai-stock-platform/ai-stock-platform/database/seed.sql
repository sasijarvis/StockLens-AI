-- database/seed.sql — optional test data
USE ai_stock_platform;

-- A few well-known NSE companies to pre-seed the companies table
-- screener_id values match Screener.in's internal IDs for these companies
INSERT IGNORE INTO companies (nse_symbol, screener_id, company_name, sector, industry, face_value) VALUES
  ('RELIANCE',   5765,  'Reliance Industries Ltd',         'Energy',               'Refineries',                      10.00),
  ('TCS',        18163, 'Tata Consultancy Services Ltd',   'IT',                   'IT Services & Consulting',        1.00),
  ('INFY',       22592, 'Infosys Ltd',                     'IT',                   'IT Services & Consulting',        5.00),
  ('HDFCBANK',   1695,  'HDFC Bank Ltd',                   'Financial Services',   'Banks - Private Sector',          1.00),
  ('TATAMOTORS', 16788, 'Tata Motors Ltd',                 'Automobile',           'Passenger & Commercial Vehicles', 2.00),
  ('WIPRO',      27013, 'Wipro Ltd',                       'IT',                   'IT Services & Consulting',        2.00),
  ('ICICIBANK',  4669,  'ICICI Bank Ltd',                  'Financial Services',   'Banks - Private Sector',          2.00),
  ('HINDUNILVR', 4321,  'Hindustan Unilever Ltd',          'FMCG',                 'Personal Products',               1.00),
  ('SUNPHARMA',  17751, 'Sun Pharmaceutical Industries',   'Pharma',               'Pharmaceuticals',                 1.00),
  ('BAJFINANCE', 3580,  'Bajaj Finance Ltd',               'Financial Services',   'Finance - NBFC',                  2.00);
