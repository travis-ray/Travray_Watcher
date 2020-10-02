# Travray_Watcher

Magento 2 module to implement some basic ant-scraping functions. It can alert when a user logs in from a different city, and when a customer views too many products, eventually cutting them off.

## Installation

Download from github - no composer package yet. You also need to add Customer attributes for:

last_login_ip

last_login_location

product_views_date

product_views_count

## Usage

After installing, go to Stores > Configuration > Travray to set options. 

The geolocation uses Maxmind, you will need to set up an account there and generate a license to include in the options.

## Contributing
Pull requests are welcome. 

## License
[MIT](https://choosealicense.com/licenses/mit/)