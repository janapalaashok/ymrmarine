-- Seed a representative set of major world ports, organized by country, for the
-- Country → Port cascading dropdown on assign_vessel.php.
-- Purely additive: existing ports (Visakhapatnam, Kakinada, Gangavaram, and any
-- ports added later via "+ Other") are untouched. Uses INSERT IGNORE keyed on
-- port_name so this is safe to re-run.
--
-- Note: this is a curated set of major/representative ports per country, not an
-- exhaustive global ports database (that would run into the thousands). Admins
-- can still add any missing port via "+ Other (Add New Port)" on the form, which
-- saves it with the currently selected country.

-- Run this once (safe to skip the ALTER line if it already exists / errors on re-run,
-- matching the other migration_*.sql files in this project).
ALTER TABLE `ports` ADD UNIQUE KEY `uniq_port_name` (`port_name`);

INSERT IGNORE INTO `ports` (`port_name`, `country`) VALUES
-- India (existing 3 ports keep their original rows; these are additional)
('Chennai Port', 'India'),
('Mumbai (Nhava Sheva/JNPT)', 'India'),
('Kolkata Port', 'India'),
('Kandla Port', 'India'),
('Cochin Port', 'India'),
('Paradip Port', 'India'),
('Mundra Port', 'India'),
('Tuticorin Port', 'India'),
('Ennore Port', 'India'),
('New Mangalore Port', 'India'),
-- China
('Shanghai Port', 'China'),
('Shenzhen Port', 'China'),
('Ningbo-Zhoushan Port', 'China'),
('Qingdao Port', 'China'),
('Guangzhou Port', 'China'),
('Tianjin Port', 'China'),
('Hong Kong Port', 'China'),
-- Singapore
('Port of Singapore', 'Singapore'),
-- UAE
('Jebel Ali Port', 'United Arab Emirates'),
('Port of Fujairah', 'United Arab Emirates'),
('Khalifa Port', 'United Arab Emirates'),
-- Saudi Arabia
('Jeddah Islamic Port', 'Saudi Arabia'),
('King Abdulaziz Port (Dammam)', 'Saudi Arabia'),
('Jubail Commercial Port', 'Saudi Arabia'),
-- Malaysia
('Port Klang', 'Malaysia'),
('Tanjung Pelepas Port', 'Malaysia'),
('Penang Port', 'Malaysia'),
-- Indonesia
('Tanjung Priok Port (Jakarta)', 'Indonesia'),
('Tanjung Perak Port (Surabaya)', 'Indonesia'),
('Belawan Port', 'Indonesia'),
-- South Korea
('Busan Port', 'South Korea'),
('Incheon Port', 'South Korea'),
-- Japan
('Port of Tokyo', 'Japan'),
('Port of Yokohama', 'Japan'),
('Port of Nagoya', 'Japan'),
('Port of Kobe', 'Japan'),
-- Sri Lanka
('Colombo Port', 'Sri Lanka'),
('Hambantota Port', 'Sri Lanka'),
-- Bangladesh
('Chattogram Port', 'Bangladesh'),
('Mongla Port', 'Bangladesh'),
-- Pakistan
('Karachi Port', 'Pakistan'),
('Port Qasim', 'Pakistan'),
('Gwadar Port', 'Pakistan'),
-- Thailand
('Laem Chabang Port', 'Thailand'),
('Bangkok Port', 'Thailand'),
-- Vietnam
('Cai Mep Port', 'Vietnam'),
('Ho Chi Minh City Port', 'Vietnam'),
('Hai Phong Port', 'Vietnam'),
-- Philippines
('Manila Port', 'Philippines'),
('Cebu Port', 'Philippines'),
-- Oman
('Port of Salalah', 'Oman'),
('Port Sultan Qaboos', 'Oman'),
-- Qatar
('Hamad Port', 'Qatar'),
-- Kuwait
('Shuwaikh Port', 'Kuwait'),
('Shuaiba Port', 'Kuwait'),
-- Bahrain
('Khalifa Bin Salman Port', 'Bahrain'),
-- Iran
('Bandar Abbas Port', 'Iran'),
('Bandar Imam Khomeini Port', 'Iran'),
-- Egypt
('Port Said', 'Egypt'),
('Alexandria Port', 'Egypt'),
('Damietta Port', 'Egypt'),
-- South Africa
('Port of Durban', 'South Africa'),
('Port of Cape Town', 'South Africa'),
('Port of Ngqura', 'South Africa'),
-- Nigeria
('Lagos Port (Apapa)', 'Nigeria'),
('Tin Can Island Port', 'Nigeria'),
-- Kenya
('Mombasa Port', 'Kenya'),
-- Netherlands
('Port of Rotterdam', 'Netherlands'),
('Port of Amsterdam', 'Netherlands'),
-- Belgium
('Port of Antwerp', 'Belgium'),
('Port of Zeebrugge', 'Belgium'),
-- Germany
('Port of Hamburg', 'Germany'),
('Port of Bremerhaven', 'Germany'),
-- UK
('Port of Felixstowe', 'United Kingdom'),
('Port of London', 'United Kingdom'),
('Port of Southampton', 'United Kingdom'),
-- France
('Port of Marseille', 'France'),
('Port of Le Havre', 'France'),
-- Spain
('Port of Valencia', 'Spain'),
('Port of Algeciras', 'Spain'),
('Port of Barcelona', 'Spain'),
-- Italy
('Port of Genoa', 'Italy'),
('Port of Gioia Tauro', 'Italy'),
('Port of Trieste', 'Italy'),
-- Greece
('Port of Piraeus', 'Greece'),
-- Turkey
('Port of Ambarli', 'Turkey'),
('Port of Mersin', 'Turkey'),
-- Russia
('Port of St. Petersburg', 'Russia'),
('Port of Novorossiysk', 'Russia'),
('Port of Vladivostok', 'Russia'),
-- USA
('Port of Los Angeles', 'United States'),
('Port of Long Beach', 'United States'),
('Port of New York and New Jersey', 'United States'),
('Port of Savannah', 'United States'),
('Port of Houston', 'United States'),
('Port of Charleston', 'United States'),
-- Canada
('Port of Vancouver', 'Canada'),
('Port of Montreal', 'Canada'),
-- Mexico
('Port of Manzanillo', 'Mexico'),
('Port of Veracruz', 'Mexico'),
-- Brazil
('Port of Santos', 'Brazil'),
('Port of Rio de Janeiro', 'Brazil'),
('Port of Paranagua', 'Brazil'),
-- Panama
('Port of Balboa', 'Panama'),
('Port of Colon', 'Panama'),
-- Chile
('Port of Valparaiso', 'Chile'),
('Port of San Antonio', 'Chile'),
-- Argentina
('Port of Buenos Aires', 'Argentina'),
-- Australia
('Port of Melbourne', 'Australia'),
('Port of Sydney', 'Australia'),
('Port of Brisbane', 'Australia'),
('Port of Fremantle', 'Australia'),
-- New Zealand
('Port of Auckland', 'New Zealand'),
('Port of Tauranga', 'New Zealand');
