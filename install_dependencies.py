import subprocess
import sys
import os

def install_package(package):
    """Install a package using pip"""
    try:
        subprocess.check_call([sys.executable, "-m", "pip", "install", package])
        print(f"✅ Successfully installed {package}")
        return True
    except subprocess.CalledProcessError as e:
        print(f"❌ Failed to install {package}: {e}")
        return False

def main():
    print("🚀 Installing Python dependencies for Airtel Risk Management System...")
    print("=" * 60)
    
    # List of required packages
    packages = [
        "Flask==2.3.3",
        "Flask-CORS==4.0.0",
        "requests==2.31.0"
    ]
    
    success_count = 0
    
    for package in packages:
        print(f"\n📦 Installing {package}...")
        if install_package(package):
            success_count += 1
    
    print("\n" + "=" * 60)
    print(f"Installation Summary: {success_count}/{len(packages)} packages installed successfully")
    
    if success_count == len(packages):
        print("✅ All dependencies installed successfully!")
        print("\n🎉 You can now run the risk scoring service:")
        print("   python risk_scoring_service.py")
    else:
        print("❌ Some packages failed to install. Please check the errors above.")
        print("\n💡 Try running: pip install -r requirements.txt")

if __name__ == "__main__":
    main()
