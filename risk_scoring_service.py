from flask import Flask, request, jsonify
from flask_cors import CORS
import math
import logging

# Configure logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

app = Flask(__name__)
CORS(app)

def calculate_risk_score(probability, impact, additional_factors=None):
    """
    Calculate risk score based on probability and impact
    Returns both numeric score and risk level
    """
    try:
        if additional_factors is None:
            additional_factors = {}
        
        # Validate inputs
        if not isinstance(probability, (int, float)) or not isinstance(impact, (int, float)):
            raise ValueError("Probability and impact must be numeric values")
        
        if not (1 <= probability <= 5) or not (1 <= impact <= 5):
            raise ValueError("Probability and impact must be between 1 and 5")
        
        # Base score calculation (1-25 scale)
        base_score = float(probability) * float(impact)
        
        # Apply additional factors
        complexity_factor = additional_factors.get('complexity', 1.0)
        urgency_factor = additional_factors.get('urgency', 1.0)
        
        # Final score calculation
        final_score = base_score * complexity_factor * urgency_factor
        
        # Determine risk level
        if final_score <= 6:
            risk_level = "Low"
        elif final_score <= 12:
            risk_level = "Medium"
        elif final_score <= 20:
            risk_level = "High"
        else:
            risk_level = "Critical"
        
        return {
            'score': round(final_score, 2),
            'level': risk_level,
            'probability': float(probability),
            'impact': float(impact),
            'calculation_method': 'probability_x_impact'
        }
        
    except Exception as e:
        logger.error(f"Error in calculate_risk_score: {str(e)}")
        raise

@app.route('/', methods=['GET'])
def home():
    """Home endpoint to verify service is running"""
    return jsonify({
        'service': 'Airtel Risk Scoring Service',
        'status': 'running',
        'version': '1.0.0',
        'endpoints': {
            'calculate_risk': '/calculate_risk (POST)',
            'risk_matrix': '/risk_matrix (GET)',
            'health': '/health (GET)'
        }
    })

@app.route('/calculate_risk', methods=['POST'])
def calculate_risk():
    """Calculate risk score based on probability and impact"""
    try:
        # Check if request has JSON data
        if not request.is_json:
            return jsonify({
                'success': False,
                'error': 'Request must contain JSON data'
            }), 400
        
        data = request.get_json()
        
        # Validate required fields
        if 'probability' not in data or 'impact' not in data:
            return jsonify({
                'success': False,
                'error': 'Both probability and impact are required'
            }), 400
        
        probability = data.get('probability')
        impact = data.get('impact')
        additional_factors = data.get('additional_factors', {})
        
        # Convert to float if they're strings
        try:
            probability = float(probability)
            impact = float(impact)
        except (ValueError, TypeError):
            return jsonify({
                'success': False,
                'error': 'Probability and impact must be numeric values'
            }), 400
        
        result = calculate_risk_score(probability, impact, additional_factors)
        
        logger.info(f"Risk calculated: P={probability}, I={impact}, Score={result['score']}, Level={result['level']}")
        
        return jsonify({
            'success': True,
            'data': result,
            'timestamp': str(math.floor(1000 * 1000))  # Simple timestamp
        })
        
    except ValueError as ve:
        logger.warning(f"Validation error: {str(ve)}")
        return jsonify({
            'success': False,
            'error': str(ve)
        }), 400
        
    except Exception as e:
        logger.error(f"Unexpected error in calculate_risk: {str(e)}")
        return jsonify({
            'success': False,
            'error': 'Internal server error occurred'
        }), 500

@app.route('/health', methods=['GET'])
def health_check():
    """Health check endpoint"""
    return jsonify({
        'status': 'healthy',
        'service': 'Airtel Risk Scoring Service',
        'timestamp': str(math.floor(1000 * 1000))
    })

@app.route('/risk_matrix', methods=['GET'])
def get_risk_matrix():
    """
    Returns risk matrix data for heat map visualization
    """
    try:
        matrix = []
        for probability in range(1, 6):
            row = []
            for impact in range(1, 6):
                score_data = calculate_risk_score(probability, impact)
                row.append({
                    'probability': probability,
                    'impact': impact,
                    'score': score_data['score'],
                    'level': score_data['level']
                })
            matrix.append(row)
        
        return jsonify({
            'success': True,
            'matrix': matrix,
            'legend': {
                'Low': '1-6',
                'Medium': '7-12', 
                'High': '13-20',
                'Critical': '21-25'
            }
        })
        
    except Exception as e:
        logger.error(f"Error generating risk matrix: {str(e)}")
        return jsonify({
            'success': False,
            'error': 'Failed to generate risk matrix'
        }), 500

@app.route('/test', methods=['GET'])
def test_endpoint():
    """Test endpoint for debugging"""
    test_cases = [
        {'probability': 1, 'impact': 1},
        {'probability': 3, 'impact': 3},
        {'probability': 5, 'impact': 5}
    ]
    
    results = []
    for case in test_cases:
        try:
            result = calculate_risk_score(case['probability'], case['impact'])
            results.append({
                'input': case,
                'output': result
            })
        except Exception as e:
            results.append({
                'input': case,
                'error': str(e)
            })
    
    return jsonify({
        'test_results': results,
        'service_status': 'operational'
    })

@app.errorhandler(404)
def not_found(error):
    return jsonify({
        'success': False,
        'error': 'Endpoint not found',
        'available_endpoints': [
            '/ (GET)',
            '/calculate_risk (POST)',
            '/risk_matrix (GET)',
            '/health (GET)',
            '/test (GET)'
        ]
    }), 404

@app.errorhandler(500)
def internal_error(error):
    return jsonify({
        'success': False,
        'error': 'Internal server error'
    }), 500

if __name__ == '__main__':
    logger.info("Starting Airtel Risk Scoring Service...")
    logger.info("Service will be available at http://localhost:5000")
    app.run(debug=True, host='0.0.0.0', port=5000)
